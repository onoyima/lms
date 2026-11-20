<?php

namespace App\Http\Controllers\QuestionBank;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Question;
use App\Models\QuestionVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Questions\RichTextSanitizer;
use App\Services\Reports\Exporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Question bank (Target 14, 17, 19, 23, 85-87, 158, 198; LMS 59-62): search, write and edit every
 * question type, versions, moderation with separation of duties, and export in the import format.
 * Answer keys are only ever sent to staff with questions.view; candidates never receive them.
 */
class QuestionController extends Controller
{
    public function __construct(private AuditLogger $audit, private RichTextSanitizer $sanitizer) {}

    private function filtered(Request $request)
    {
        $q = trim((string) $request->query('q'));

        return Question::query()
            ->when($request->integer('course_id'), fn ($w, $id) => $w->where('course_id', $id))
            ->when($request->string('type')->toString(), fn ($w, $t) => $w->where('type', $t))
            ->when($request->string('status')->toString(), fn ($w, $t) => $w->where('status', $t))
            ->when($request->string('topic')->toString(), fn ($w, $t) => $w->where('topic', $t))
            ->when($request->string('difficulty')->toString(), fn ($w, $t) => $w->where('difficulty', $t))
            ->when($q !== '', fn ($w) => $w->where('stem_html', 'like', '%'.addcslashes($q, '%_\\').'%'));
    }

    public function index(Request $request): Response
    {
        $questions = $this->filtered($request)->with(['options', 'course:id,code,title'])->latest('id')->paginate(20)->withQueryString()
            ->through(fn (Question $q) => [
                'id' => $q->id, 'type' => $q->type, 'stem_html' => $q->stem_html, 'mark' => (float) $q->mark, 'status' => $q->status,
                'topic' => $q->topic, 'difficulty' => $q->difficulty, 'outcome' => $q->outcome, 'course' => $q->course?->code, 'version' => $q->version,
                'options' => $q->options->map(fn ($o) => ['label' => $o->label, 'html' => $o->body_html, 'correct' => $o->is_correct]),
                'matching' => $q->matching, 'accepted' => $q->accepted_answers['answers'] ?? null, 'numeric' => $q->numeric_answer,
                'mine' => $q->created_by === $request->user()->id,
            ]);

        return Inertia::render('question-bank/index', [
            'questions' => $questions,
            'courses' => Course::orderBy('code')->get(['id', 'code', 'title']),
            'topics' => Question::whereNotNull('topic')->distinct()->orderBy('topic')->pluck('topic'),
            'filters' => $request->only(['course_id', 'type', 'status', 'topic', 'difficulty', 'q']),
            'canCreate' => $request->user()->can('questions.create'),
            'canModerate' => $request->user()->can('questions.moderate'),
        ]);
    }

    public function create(): Response
    {
        return $this->editor(null);
    }

    public function edit(Question $question): Response
    {
        return $this->editor($question->load('options'));
    }

    private function editor(?Question $q): Response
    {
        return Inertia::render('question-bank/editor', [
            'question' => $q ? [
                'id' => $q->id, 'type' => $q->type, 'stem_html' => $q->stem_html, 'explanation_html' => $q->explanation_html, 'mark' => (float) $q->mark,
                'topic' => $q->topic, 'difficulty' => $q->difficulty, 'outcome' => $q->outcome, 'course_id' => $q->course_id, 'status' => $q->status, 'version' => $q->version,
                'options' => $q->options->map(fn ($o) => ['html' => $o->body_html, 'correct' => (bool) $o->is_correct])->values(),
                'numeric' => $q->numeric_answer, 'accepted' => $q->accepted_answers,
                'pairs' => collect($q->matching['left'] ?? [])->map(fn ($l) => ['left' => $l['html'], 'right' => collect($q->matching['right'])->firstWhere('key', $q->matching['answer'][$l['key']] ?? null)['html'] ?? ''])->values(),
                'used' => DB::table('attempt_questions')->where('question_id', $q->id)->exists(),
                'blanks' => $q->accepted_answers['blanks'] ?? [], 'file' => $q->accepted_answers['file'] ?? null, 'passage_id' => $q->passage_id,
            ] : null,
            'passages' => \App\Models\QuestionPassage::latest('id')->limit(200)->get(['id', 'title', 'course_id']),
            'fileKinds' => array_keys(Question::FILE_KINDS),
            'courses' => Course::orderBy('code')->get(['id', 'code', 'title', 'learning_outcomes']),
            'topics' => Question::whereNotNull('topic')->distinct()->orderBy('topic')->pluck('topic'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->save($request, new Question(['created_by' => $request->user()->id, 'status' => 'draft']));

        return redirect()->route('question-bank.index')->with('success', 'Question saved as a draft. A moderator approves it for exams.');
    }

    public function update(Request $request, Question $question): RedirectResponse
    {
        $this->save($request, $question);

        return redirect()->route('question-bank.index')->with('success', "Question saved (version {$question->version}).");
    }

    private function save(Request $request, Question $q): Question
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(Question::TYPES)], 'stem_html' => ['required', 'string', 'max:200000'], 'explanation_html' => ['nullable', 'string', 'max:50000'],
            'mark' => ['required', 'numeric', 'min:0', 'max:100'], 'topic' => ['nullable', 'string', 'max:190'], 'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'outcome' => ['nullable', 'string', 'max:190'], 'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())],
            'options' => ['array', 'max:10'], 'options.*.html' => ['required', 'string', 'max:20000'], 'options.*.correct' => ['boolean'],
            'numeric.value' => ['required_if:type,numeric', 'nullable', 'numeric'], 'numeric.tolerance' => ['nullable', 'numeric', 'min:0'],
            'accepted' => ['array', 'max:20'], 'accepted.*' => ['string', 'max:190'], 'case_sensitive' => ['boolean'],
            'pairs' => ['array', 'max:20'], 'pairs.*.left' => ['required', 'string', 'max:2000'], 'pairs.*.right' => ['required', 'string', 'max:2000'],
            'distractors' => ['array', 'max:10'], 'distractors.*' => ['string', 'max:2000'],
            'blanks' => ['array', 'max:30'], 'blanks.*.answers' => ['array', 'max:20'], 'blanks.*.answers.*' => ['nullable', 'string', 'max:190'],
            'blanks.*.case_sensitive' => ['boolean'], 'blanks.*.value' => ['nullable', 'numeric'], 'blanks.*.tolerance' => ['nullable', 'numeric', 'min:0'],
            'file.kinds' => ['array'], 'file.kinds.*' => [Rule::in(array_keys(Question::FILE_KINDS))], 'file.max_mb' => ['nullable', 'integer', 'min:1', 'max:100'],
            'passage_id' => ['nullable', 'integer', Rule::exists('question_passages', 'id')->where('tenant_id', tenant_id())],
        ]);
        foreach (['options', 'pairs'] as $list) {
            isset($data[$list]) && ksort($data[$list]);
        }
        if (isset($data['blanks'])) {
            ksort($data['blanks']); // keep blank 1, 2, 3… in order whatever order validation rebuilt them in
        }
        if ($data['type'] === 'fill_blank') {
            $n = count(Question::blankNumbers($data['stem_html']));
            $n === 0 && throw ValidationException::withMessages(['stem_html' => 'Mark each blank in the text as [[1]], [[2]] and so on.']);
            $blanks = array_values($data['blanks'] ?? []);
            count($blanks) !== $n && throw ValidationException::withMessages(['blanks' => "The text has $n blanks; give the answers for each."]);
            foreach ($blanks as $i => $b) {
                (array_filter((array) ($b['answers'] ?? [])) === [] && ! isset($b['value'])) && throw ValidationException::withMessages(['blanks' => 'Give at least one accepted answer for blank '.($i + 1).'.']);
            }
        }
        $data['type'] === 'ordering' && count($data['options'] ?? []) < 2 && throw ValidationException::withMessages(['options' => 'Give at least two items, in the right order.']);
        $choice = in_array($data['type'], ['single_choice', 'multiple_choice', 'true_false', 'multi_true_false', 'ordering'], true);
        if ($data['type'] === 'ordering') {
            // items in the right order; no "correct" flag
        } elseif ($data['type'] === 'multi_true_false') {
            // statements, each marked true (correct) or false; any mix is allowed
            count($data['options'] ?? []) < 2 && throw ValidationException::withMessages(['options' => 'Give at least two statements.']);
        } elseif ($choice) {
            $correct = collect($data['options'] ?? [])->where('correct', true)->count();
            count($data['options'] ?? []) < 2 && throw ValidationException::withMessages(['options' => 'Give at least two options.']);
            $correct === 0 && throw ValidationException::withMessages(['options' => 'Mark the correct option.']);
            $data['type'] !== 'multiple_choice' && $correct > 1 && throw ValidationException::withMessages(['options' => 'Only one option can be correct for this type.']);
        }
        $data['type'] === 'matching' && count($data['pairs'] ?? []) < 2 && throw ValidationException::withMessages(['pairs' => 'A matching question needs at least two pairs.']);

        return DB::transaction(function () use ($data, $q, $choice, $request) {
            if ($q->exists) {
                $live = DB::table('attempt_questions as aq')->join('attempts as a', 'a.id', '=', 'aq.attempt_id')
                    ->where('aq.question_id', $q->id)->whereIn('a.status', ['in_progress', 'paused', 'locked'])->exists();
                abort_if($live, 409, 'Candidates are answering this question right now. Edit it after the sitting ends.');
            }
            if ($q->exists) {
                // keep the version candidates saw; edits make a new version that must be approved again
                QuestionVersion::firstOrCreate(['question_id' => $q->id, 'version' => $q->version], ['snapshot' => $q->load('options')->snapshot(), 'created_at' => now()]);
                $q->version++;
                if ($q->status === 'approved') {
                    $q->status = 'in_review';
                }
            }
            $clean = fn ($h) => $this->sanitizer->clean($h);
            $pairs = $data['pairs'] ?? [];
            $pairs['_distractors'] = $data['distractors'] ?? [];
            $q->fill([
                'type' => $data['type'], 'stem_html' => $clean($data['stem_html']), 'explanation_html' => isset($data['explanation_html']) ? $clean($data['explanation_html']) : null,
                'mark' => $data['mark'], 'topic' => $data['topic'] ?? null, 'difficulty' => $data['difficulty'] ?? null, 'outcome' => $data['outcome'] ?? null,
                'course_id' => $data['course_id'] ?? null,
                'numeric_answer' => $data['type'] === 'numeric' ? ['value' => (float) $data['numeric']['value'], 'tolerance' => (float) ($data['numeric']['tolerance'] ?? 0)] : null,
                'matching' => $data['type'] === 'matching' ? Question::buildMatching($pairs, $clean) : null,
                'accepted_answers' => match (true) {
                    $data['type'] === 'short_answer' && ! empty($data['accepted']) => ['answers' => array_values(array_filter($data['accepted'])), 'case_sensitive' => (bool) ($data['case_sensitive'] ?? false)],
                    $data['type'] === 'fill_blank' => ['blanks' => array_map(fn ($b) => array_filter([
                        'answers' => array_values(array_filter(array_map('trim', (array) ($b['answers'] ?? [])))), 'case_sensitive' => (bool) ($b['case_sensitive'] ?? false),
                        'value' => isset($b['value']) && $b['value'] !== '' ? (float) $b['value'] : null, 'tolerance' => isset($b['tolerance']) ? (float) $b['tolerance'] : null,
                    ], fn ($v) => $v !== null), array_values($data['blanks']))],
                    $data['type'] === 'file_upload' => ['file' => ['kinds' => array_values($data['file']['kinds'] ?? ['image', 'pdf', 'document']) ?: ['image', 'pdf', 'document'], 'max_mb' => (int) ($data['file']['max_mb'] ?? 10)]],
                    default => null,
                },
                'passage_id' => $data['passage_id'] ?? null,
            ])->save();
            // papers already delivered keep their frozen copy and key, so options can be replaced safely
            $q->options()->delete();
            if ($choice) {
                foreach ($data['options'] as $i => $o) {
                    $q->options()->create(['label' => chr(65 + $i), 'body_html' => $clean($o['html']), 'is_correct' => $data['type'] === 'ordering' || (bool) ($o['correct'] ?? false), 'position' => $i]);
                }
            }
            $this->audit->record($q->wasRecentlyCreated ? 'question.created' : 'question.edited', $q, ['version' => $q->version, 'type' => $q->type]);

            return $q;
        });
    }

    /** A reading passage or case study for several questions. */
    public function storePassage(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:190'], 'body_html' => ['required', 'string', 'max:500000'],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')->where('tenant_id', tenant_id())]]);
        $p = \App\Models\QuestionPassage::create(['title' => $data['title'], 'body_html' => $this->sanitizer->clean($data['body_html']), 'course_id' => $data['course_id'] ?? null, 'created_by' => $request->user()->id]);
        $this->audit->record('question.passage_created', $p, []);

        return response()->json(['id' => $p->id, 'title' => $p->title]);
    }

    /** Pictures, sound and video for questions (listening tests, clips, diagrams), stored with the institution's files. */
    public function media(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:204800', 'mimes:png,jpg,jpeg,gif,webp,mp3,m4a,wav,ogg,oga,mp4,webm,mov']]);
        $ext = strtolower($data['file']->getClientOriginalExtension());
        $path = $data['file']->storeAs('question-media/'.tenant_id().'/uploads', \Illuminate\Support\Str::random(16).'.'.$ext, 'public');
        $kind = match (true) {
            in_array($ext, ['mp3', 'm4a', 'wav', 'ogg', 'oga'], true) => 'audio',
            in_array($ext, ['mp4', 'webm', 'mov'], true) => 'video',
            default => 'image',
        };
        $this->audit->record('question.media_uploaded', null, ['kind' => $kind, 'size' => $data['file']->getSize()]);
        $url = '/storage/'.$path;

        return response()->json(['url' => $url, 'kind' => $kind, 'html' => match ($kind) {
            'audio' => '<audio controls src="'.$url.'"></audio>',
            'video' => '<video controls src="'.$url.'"></video>',
            default => '<figure><img src="'.$url.'" alt=""></figure>',
        }]);
    }

    /** Server-side sanitised preview for the editor, so unsafe HTML is never rendered in the browser. */
    public function preview(Request $request)
    {
        $data = $request->validate(['html' => ['array', 'max:40'], 'html.*' => ['nullable', 'string', 'max:200000']]);

        return response()->json(['html' => array_map(fn ($h) => $this->sanitizer->clean((string) $h), $data['html'] ?? [])]);
    }

    /** Moderation (Target 14, 84-87; LMS 62): a setter cannot approve their own questions. */
    public function moderate(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer'], 'status' => ['required', 'in:approved,in_review,draft,retired']]);
        $questions = Question::whereIn('id', $data['ids'])->get();
        $skipped = 0;
        foreach ($questions as $q) {
            if ($data['status'] === 'approved' && $q->created_by === $request->user()->id) {
                $skipped++;

                continue;
            }
            $q->update(['status' => $data['status']]);
        }
        $this->audit->record('questions.'.$data['status'], null, ['ids' => $questions->pluck('id')->all(), 'skipped_own' => $skipped]);

        return back()->with('success', ($questions->count() - $skipped).' questions marked '.$data['status'].'.'.($skipped ? " $skipped of your own were skipped — another moderator must approve them." : ''));
    }

    /** Export in the same text format the importer reads (round-trip), or a spreadsheet listing. */
    public function export(Request $request, Exporter $exporter)
    {
        $format = $request->query('format', 'txt');
        $questions = $this->filtered($request)->with('options')->orderBy('id')->limit(5000)->get();
        $this->audit->record('questions.exported', null, ['count' => $questions->count(), 'format' => $format, 'filters' => $request->query()]);
        if ($format === 'xlsx') {
            return $exporter->xlsx('questions-'.now()->format('Ymd'), 'Questions', ['ID', 'Type', 'Question', 'Mark', 'Topic', 'Difficulty', 'Outcome', 'Status', 'Version'],
                $questions->map(fn ($q) => [$q->id, $q->type, trim(html_entity_decode(strip_tags($q->stem_html))), (float) $q->mark, $q->topic, $q->difficulty, $q->outcome, $q->status, $q->version]));
        }
        $text = '';
        foreach ($questions->values() as $i => $q) {
            $plain = fn ($h) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<img[^>]*src="([^"]+)"[^>]*>#i', '[image: $1]', (string) $h)), ENT_QUOTES)));
            $text .= ($i + 1).'. '.$plain($q->stem_html)."\n";
            foreach ($q->options as $j => $o) {
                $text .= ($o->is_correct ? '*' : '').chr(65 + $j).'. '.$plain($o->body_html)."\n";
            }
            if ($q->type === 'matching') {
                foreach ($q->matching['left'] ?? [] as $l) {
                    $r = collect($q->matching['right'])->firstWhere('key', $q->matching['answer'][$l['key']] ?? null);
                    $text .= 'Pair: '.$plain($l['html']).' = '.$plain($r['html'] ?? '')."\n";
                }
            }
            if ($q->type === 'numeric' && $q->numeric_answer) {
                $text .= 'Answer: '.$q->numeric_answer['value']."\n".(($q->numeric_answer['tolerance'] ?? 0) ? 'Tolerance: '.$q->numeric_answer['tolerance']."\n" : '');
            }
            if ($q->type === 'short_answer' && $q->accepted_answers) {
                $text .= 'Answer: '.implode('; ', $q->accepted_answers['answers'])."\n";
            }
            in_array($q->type, ['essay', 'short_answer'], true) && $text .= 'Type: '.$q->type."\n";
            $text .= 'Mark: '.(float) $q->mark."\n";
            $q->topic && $text .= 'Topic: '.$q->topic."\n";
            $q->difficulty && $text .= 'Difficulty: '.$q->difficulty."\n";
            $q->outcome && $text .= 'Outcome: '.$q->outcome."\n";
            $q->explanation_html && $text .= 'Explanation: '.$plain($q->explanation_html)."\n";
            $text .= "\n";
        }

        return response($text, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="questions-'.now()->format('Ymd').'.txt"']);
    }
}
