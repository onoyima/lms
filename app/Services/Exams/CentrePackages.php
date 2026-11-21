<?php

namespace App\Services\Exams;

use App\Models\Attempt;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamCentre;
use App\Models\ExamSession;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Exam centres without internet (the old system's centre packages, .cbtpkg):
 *
 *  1. the main server packs one sitting for a centre: the paper (questions, banks, pictures and
 *     answer keys), the candidates and the times, signed with the centre's key;
 *  2. the centre's own copy of the platform reads the package and runs the sitting locally;
 *  3. afterwards the centre packs the scripts (every attempt, its paper and answers), signed;
 *  4. the main server reads them, marks every script with its own key and updates the results.
 *
 * Options are matched by their position in the question (ids differ between the two servers).
 * A package that is changed or signed with another key is refused.
 */
class CentrePackages
{
    public const FORMAT = 'cbtpkg';

    public function __construct(private Marker $marker, private AuditLogger $audit) {}

    // ── on the main server ─────────────────────────────────────────────────────

    public function packSitting(ExamSession $session, ExamCentre $centre): string
    {
        $exam = $session->exam()->with('sections.questions.options')->firstOrFail();
        $bankIds = collect($exam->sections)->flatMap(fn ($s) => collect($s->blueprint ?? [])->flatMap(fn ($r) => $r['bank_ids'] ?? []))->unique()->values();
        $banks = QuestionBank::whereIn('id', $bankIds)->get();
        // rules that draw from the course's questions take them in a bank of their own at the centre
        $pool = $this->poolQuestions($exam)->pluck('id')->flip();
        $poolRef = $this->ref('b', 0);
        $questions = $exam->sections->flatMap(fn ($s) => $s->questions)
            ->merge(Question::with('options')->whereIn('question_bank_id', $bankIds)->whereIn('status', ['approved', 'draft', 'in_review'])->get())
            ->merge($this->poolQuestions($exam))
            ->unique('id')->values();
        $bankOf = fn ($q) => $q->question_bank_id && $bankIds->contains($q->question_bank_id) ? $this->ref('b', $q->question_bank_id) : ($pool->has($q->id) ? $poolRef : null);
        $pictures = [];
        $questions->each(function ($q) use (&$pictures) {
            foreach ([$q->stem_html, $q->explanation_html, ...$q->options->pluck('body_html')->all()] as $html) {
                preg_match_all('#src="/storage/([^"]+)"#', (string) $html, $m);
                foreach ($m[1] as $path) {
                    if (! isset($pictures[$path]) && Storage::disk('public')->exists($path) && count($pictures) < 400) {
                        $pictures[$path] = base64_encode(Storage::disk('public')->get($path));
                    }
                }
            }
        });
        $session->forceFill(['exam_centre_id' => $centre->id])->save();
        $candidates = ExamCandidate::with('user')->where('exam_session_id', $session->id)->get();
        $payload = [
            'format' => self::FORMAT, 'kind' => 'sitting', 'version' => 1, 'centre' => $centre->code, 'made_at' => now()->toIso8601String(),
            'origin' => $this->ref('s', $session->id),
            'exam' => ['ref' => $this->ref('e', $exam->id)] + $exam->only(['title', 'code', 'type', 'category', 'instructions', 'total_mark', 'pass_mark', 'duration_minutes', 'max_attempts', 'score_policy', 'marking_policy', 'settings', 'proctoring', 'channels']),
            'banks' => $banks->map(fn ($b) => ['ref' => $this->ref('b', $b->id), 'name' => $b->name, 'topic' => $b->topic])->values()
                ->when($pool->isNotEmpty(), fn ($c) => $c->push(['ref' => $poolRef, 'name' => 'Course questions', 'topic' => null])),
            'questions' => $questions->map(fn ($q) => ['ref' => $this->ref('q', $q->id), 'bank' => $bankOf($q)]
                + $q->only(['type', 'stem_html', 'explanation_html', 'topic', 'difficulty', 'mark', 'scoring', 'numeric_answer', 'matching', 'accepted_answers', 'outcome', 'version'])
                + ['options' => $q->options->map(fn ($o) => $o->only(['label', 'body_html', 'is_correct', 'position']))->values()])->values(),
            'sections' => $exam->sections->map(fn ($s) => $s->only(['title', 'instructions', 'position', 'answer_any']) + [
                'blueprint' => collect($s->blueprint ?? [])->map(fn ($r) => match (true) {
                    isset($r['draw']) => $r,
                    ! empty($r['bank_ids']) => ['bank_refs' => collect($r['bank_ids'])->map(fn ($id) => $this->ref('b', $id))->all()] + \Illuminate\Support\Arr::except($r, ['bank_ids']),
                    default => ['bank_refs' => [$poolRef]] + $r,
                })->all(),
                'questions' => $s->questions->map(fn ($q) => ['ref' => $this->ref('q', $q->id), 'mark' => $q->pivot->mark, 'position' => $q->pivot->position])->values(),
            ])->values(),
            'sitting' => ['ref' => $this->ref('s', $session->id)] + $session->only(['name', 'mode', 'late_entry_minutes', 'grace_seconds', 'is_practice'])
                + ['starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String()],
            // passwords travel as hashes so candidates sign in at the centre as they always do
            'candidates' => $candidates->map(fn ($c) => ['identifier' => $c->user?->identifier, 'email' => $c->user?->email, 'name' => $c->user?->name, 'user_type' => $c->user?->user_type,
                'password_hash' => $c->user?->password, 'extra_time_minutes' => $c->extra_time_minutes, 'accommodations' => $c->accommodations, 'eligibility' => $c->eligibility])->values(),
            'pictures' => $pictures,
        ];
        $centre->forceFill(['last_package_at' => now()])->save();
        $this->audit->record('centre.package_made', $session, ['centre' => $centre->code, 'candidates' => $candidates->count(), 'questions' => $questions->count()]);

        return $this->sign($payload, $centre);
    }

    /** Scripts back from the centre: each attempt is stored once, marked here, and the results refreshed. */
    public function readResults(string $file, ExamSession $session, ExamCentre $centre): array
    {
        $p = $this->open($file, $centre, 'results');
        abort_unless(($p['origin'] ?? null) === $this->ref('s', $session->id), 422, 'These results are for a different sitting.');
        $exam = $session->exam;
        $added = 0;
        $skipped = 0;
        $missing = [];
        DB::transaction(function () use ($p, $session, $exam, $centre, &$added, &$skipped, &$missing) {
            foreach ($p['attempts'] as $a) {
                $user = $this->findUser($a['identifier'] ?? null, $a['email'] ?? null);
                if (! $user) {
                    $missing[] = $a['identifier'] ?? $a['email'];

                    continue;
                }
                $ref = $centre->code.':'.$a['id'];
                if (Attempt::where('centre_ref', $ref)->exists()) {
                    $skipped++;

                    continue;
                }
                $candidate = ExamCandidate::firstOrCreate(['exam_session_id' => $session->id, 'user_id' => $user->id], ['eligibility' => 'eligible']);
                $attempt = Attempt::forceCreate([
                    'tenant_id' => $exam->tenant_id, 'exam_id' => $exam->id, 'exam_session_id' => $session->id, 'exam_candidate_id' => $candidate->id, 'user_id' => $user->id,
                    'attempt_no' => (int) (Attempt::where('exam_id', $exam->id)->where('user_id', $user->id)->max('attempt_no') ?? 0) + 1,
                    'status' => in_array($a['status'], Attempt::CLOSED, true) ? $a['status'] : 'auto_submitted', 'channel' => 'centre',
                    'started_at' => $a['started_at'], 'deadline_at' => $a['deadline_at'], 'submitted_at' => $a['submitted_at'] ?? $a['deadline_at'],
                    'submit_reason' => 'exam centre '.$centre->code, 'paused_seconds' => (int) ($a['paused_seconds'] ?? 0), 'token_hash' => hash('sha256', Str::random(40)),
                    'centre_ref' => $ref,
                ]);
                $rows = [];
                foreach ($a['questions'] as $q) {
                    $question = $this->question($q['ref']);
                    if (! $question) {
                        continue;
                    }
                    $ids = $question->options->pluck('id')->all();
                    $rows[] = ['attempt_id' => $attempt->id, 'question_id' => $question->id, 'question_version' => $question->version, 'exam_section_id' => $exam->sections()->value('id'),
                        'position' => $q['position'], 'mark' => $q['mark'],
                        'option_order' => json_encode($question->type === 'matching' ? ($q['option_order'] ?? []) : array_values(array_filter(array_map(fn ($i) => $ids[$i] ?? null, $q['option_order'] ?? []), fn ($v) => $v !== null))),
                        // the key comes from this server's question, not from the centre
                        'key' => json_encode($this->keyFor($question))];
                    if (array_key_exists('response', $q) && $q['response'] !== null) {
                        DB::table('attempt_answers')->insert(['attempt_id' => $attempt->id, 'question_id' => $question->id, 'response' => json_encode($this->fromPositions($q['response'], $ids)),
                            'client_seq' => 1, 'flagged' => (bool) ($q['flagged'] ?? false), 'answered_at' => ($at = $q['answered_at'] ?? $a['submitted_at'] ?? null) ? \Illuminate\Support\Carbon::parse($at)->format('Y-m-d H:i:s.v') : null]);
                    }
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('attempt_questions')->insert($chunk);
                }
                $this->marker->markAttempt($attempt);
                $added++;
            }
        });
        $centre->forceFill(['last_results_at' => now()])->save();
        $this->audit->record('centre.results_read', $session, ['centre' => $centre->code, 'added' => $added, 'already_here' => $skipped, 'not_found' => count($missing)]);

        return ['added' => $added, 'skipped' => $skipped, 'missing' => $missing];
    }

    // ── on the centre's server ─────────────────────────────────────────────────

    /** Sets up the sitting from a package: the exam, its questions and banks, the candidates and the times. */
    public function readSitting(string $file, ExamCentre $main, User $by): ExamSession
    {
        $p = $this->open($file, $main, 'sitting');

        return DB::transaction(function () use ($p, $by) {
            foreach ($p['pictures'] ?? [] as $path => $data) {
                Storage::disk('public')->put($path, base64_decode($data));
            }
            $exam = Exam::firstOrNew(['legacy_ref' => $p['exam']['ref']]);
            $exam->fill(\Illuminate\Support\Arr::except($p['exam'], ['ref']) + ['created_by' => $exam->created_by ?? $by->id, 'status' => 'scheduled'])->save();
            $banks = [];
            foreach ($p['banks'] as $b) {
                $banks[$b['ref']] = QuestionBank::updateOrCreate(['centre_ref' => $b['ref']], ['name' => $b['name'], 'topic' => $b['topic'], 'exam_id' => $exam->id])->id;
            }
            $questions = [];
            foreach ($p['questions'] as $q) {
                $model = Question::withTrashed()->firstOrNew(['centre_ref' => $q['ref']]);
                $model->fill(\Illuminate\Support\Arr::only($q, ['type', 'stem_html', 'explanation_html', 'topic', 'difficulty', 'mark', 'scoring', 'numeric_answer', 'matching', 'accepted_answers', 'outcome', 'version'])
                    + ['question_bank_id' => $q['bank'] ? ($banks[$q['bank']] ?? null) : null, 'status' => 'approved', 'created_by' => $model->created_by ?? $by->id])->save();
                $model->deleted_at && $model->restore();
                $model->options()->delete();
                foreach ($q['options'] as $o) {
                    $model->options()->create($o);
                }
                $questions[$q['ref']] = $model->id;
            }
            if (! Attempt::where('exam_id', $exam->id)->exists()) {
                $exam->sections()->delete();
                foreach ($p['sections'] as $s) {
                    $section = $exam->sections()->create(\Illuminate\Support\Arr::only($s, ['title', 'instructions', 'position', 'answer_any']) + [
                        'blueprint' => collect($s['blueprint'])->map(fn ($r) => isset($r['bank_refs']) ? ['bank_ids' => collect($r['bank_refs'])->map(fn ($ref) => $banks[$ref] ?? null)->filter()->values()->all()] + \Illuminate\Support\Arr::except($r, ['bank_refs']) : $r)->all(),
                    ]);
                    foreach ($s['questions'] as $sq) {
                        isset($questions[$sq['ref']]) && $section->questions()->attach($questions[$sq['ref']], ['mark' => $sq['mark'], 'position' => $sq['position']]);
                    }
                }
            }
            $session = ExamSession::firstOrNew(['centre_ref' => $p['sitting']['ref']]);
            $session->fill(\Illuminate\Support\Arr::except($p['sitting'], ['ref']) + ['exam_id' => $exam->id, 'status' => $session->status ?? 'open'])->save();
            foreach ($p['candidates'] as $c) {
                $user = $this->findUser($c['identifier'], $c['email']);
                if (! $user) {
                    $user = User::forceCreate(['tenant_id' => tenant_id(), 'name' => $c['name'] ?: ($c['identifier'] ?? $c['email']), 'email' => $c['email'], 'identifier' => $c['identifier'],
                        'user_type' => $c['user_type'] ?: 'student', 'status' => 'active', 'password' => $c['password_hash'] ?: Hash::make(Str::random(24))]);
                    $user->user_type === 'student' && $user->syncRoles(['student']);
                } elseif (! empty($c['password_hash'])) {
                    $user->forceFill(['password' => $c['password_hash']])->save(); // same password as on the main server
                }
                ExamCandidate::updateOrCreate(['exam_session_id' => $session->id, 'user_id' => $user->id], \Illuminate\Support\Arr::only($c, ['extra_time_minutes', 'accommodations', 'eligibility']));
            }
            $this->audit->record('centre.package_read', $session, ['exam' => $exam->title, 'candidates' => count($p['candidates'])], $by->id);

            return $session;
        });
    }

    /** The sitting's scripts, packed for the main server. */
    public function packResults(ExamSession $session, ExamCentre $main): string
    {
        abort_unless($session->centre_ref, 422, 'This sitting did not come from a main server package.');
        $attempts = Attempt::with('user')->where('exam_session_id', $session->id)->whereIn('status', Attempt::CLOSED)->get();
        $payload = [
            'format' => self::FORMAT, 'kind' => 'results', 'version' => 1, 'centre' => $main->code, 'made_at' => now()->toIso8601String(), 'origin' => $session->centre_ref,
            'attempts' => $attempts->map(function (Attempt $a) {
                $qs = DB::table('attempt_questions as aq')->leftJoin('attempt_answers as aa', fn ($j) => $j->on('aa.attempt_id', '=', 'aq.attempt_id')->on('aa.question_id', '=', 'aq.question_id'))
                    ->where('aq.attempt_id', $a->id)->orderBy('aq.position')->get(['aq.question_id', 'aq.position', 'aq.mark', 'aq.option_order', 'aa.response', 'aa.flagged', 'aa.answered_at']);
                $models = Question::withTrashed()->with('options')->whereIn('id', $qs->pluck('question_id'))->get()->keyBy('id');

                return ['id' => $a->id, 'identifier' => $a->user?->identifier, 'email' => $a->user?->email, 'status' => $a->status,
                    'started_at' => $a->started_at?->toIso8601String(), 'deadline_at' => $a->deadline_at?->toIso8601String(), 'submitted_at' => $a->submitted_at?->toIso8601String(), 'paused_seconds' => $a->paused_seconds,
                    'questions' => $qs->map(function ($r) use ($models) {
                        $q = $models[$r->question_id] ?? null;
                        $ids = $q ? $q->options->pluck('id')->all() : [];
                        $order = json_decode($r->option_order ?? '[]', true) ?: [];

                        return ['ref' => $q?->centre_ref, 'position' => $r->position, 'mark' => (float) $r->mark, 'flagged' => (bool) $r->flagged, 'answered_at' => $r->answered_at,
                            'option_order' => $q?->type === 'matching' ? $order : array_values(array_filter(array_map(fn ($id) => array_search($id, $ids, false), $order), fn ($v) => $v !== false)),
                            'response' => $r->response !== null ? $this->toPositions(json_decode($r->response, true), $ids) : null];
                    })->filter(fn ($x) => $x['ref'])->values()->all()];
            })->values()->all(),
        ];
        $this->audit->record('centre.results_packed', $session, ['attempts' => $attempts->count()]);

        return $this->sign($payload, $main);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function ref(string $kind, int $id): string
    {
        return 'm'.tenant_id().$kind.$id;
    }

    private function sign(array $payload, ExamCentre $centre): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return json_encode(['format' => self::FORMAT, 'signature' => hash_hmac('sha256', $body, $centre->secret), 'payload' => base64_encode(gzencode($body, 6))]);
    }

    private function open(string $file, ExamCentre $centre, string $kind): array
    {
        $outer = json_decode($file, true);
        abort_unless(is_array($outer) && ($outer['format'] ?? null) === self::FORMAT && isset($outer['payload'], $outer['signature']), 422, 'This is not an exam centre package.');
        $body = @gzdecode((string) base64_decode($outer['payload'], true));
        abort_unless(is_string($body) && hash_equals(hash_hmac('sha256', $body, $centre->secret), (string) $outer['signature']), 422, 'The package was changed, or it was signed with another centre\'s key.');
        $p = json_decode($body, true);
        abort_unless(($p['kind'] ?? null) === $kind, 422, $kind === 'sitting' ? 'This file holds results, not a sitting.' : 'This file holds a sitting, not results.');

        return $p;
    }

    private function findUser(?string $identifier, ?string $email): ?User
    {
        return User::where(function ($q) use ($identifier, $email) {
            $identifier && $q->orWhere('identifier', $identifier);
            $email && $q->orWhere('email', $email);
        })->first();
    }

    private function question(?string $ref): ?Question
    {
        if (! $ref || ! preg_match('/^m'.tenant_id().'q(\d+)$/', $ref, $m)) {
            return null;
        }

        return Question::withTrashed()->with('options')->find((int) $m[1]);
    }

    /** Option ids in a response → their positions in the question (and back), so both servers agree. */
    private function toPositions(mixed $r, array $ids): mixed
    {
        if (! is_array($r)) {
            return $r;
        }
        $pos = fn ($id) => ($i = array_search((int) $id, array_map('intval', $ids), true)) === false ? null : $i;
        foreach (['options', 'order'] as $k) {
            if (isset($r[$k]) && is_array($r[$k])) {
                $r[$k] = array_values(array_filter(array_map($pos, $r[$k]), fn ($v) => $v !== null));
            }
        }
        if (isset($r['tf']) && is_array($r['tf'])) {
            $r['tf'] = collect($r['tf'])->mapWithKeys(fn ($v, $id) => [(string) $pos($id) => $v])->except([''])->all();
        }

        return $r;
    }

    private function fromPositions(mixed $r, array $ids): mixed
    {
        if (! is_array($r)) {
            return $r;
        }
        $id = fn ($i) => $ids[(int) $i] ?? null;
        foreach (['options', 'order'] as $k) {
            if (isset($r[$k]) && is_array($r[$k])) {
                $r[$k] = array_values(array_filter(array_map($id, $r[$k]), fn ($v) => $v !== null));
            }
        }
        if (isset($r['tf']) && is_array($r['tf'])) {
            $r['tf'] = collect($r['tf'])->mapWithKeys(fn ($v, $i) => [(string) $id($i) => $v])->except([''])->all();
        }

        return $r;
    }

    /** The answer key for a question, as the exam screen's paper builder writes it. */
    private function keyFor(Question $q): ?array
    {
        return match ($q->type) {
            'single_choice', 'multiple_choice', 'true_false' => ['options' => $q->options->where('is_correct', true)->pluck('id')->values()->all()] + ($q->scoring ? ['scoring' => $q->scoring] : []),
            'multi_true_false' => ['tf' => $q->options->mapWithKeys(fn ($o) => [(string) $o->id => (bool) $o->is_correct])->all()] + ($q->scoring ? ['scoring' => $q->scoring] : []),
            'ordering' => ['order' => $q->options->sortBy('position')->pluck('id')->values()->all()],
            'fill_blank' => ['blanks' => $q->accepted_answers['blanks'] ?? []],
            'numeric' => $q->numeric_answer,
            'matching' => ['pairs' => $q->matching['answer'] ?? []],
            'short_answer' => $q->accepted_answers ?: null,
            default => null,
        };
    }

    /** Course pool questions a blueprint rule without banks could draw (so the centre can draw them too). */
    private function poolQuestions(Exam $exam)
    {
        $needsPool = $exam->sections->contains(fn ($s) => collect($s->blueprint ?? [])->contains(fn ($r) => ! isset($r['draw']) && empty($r['bank_ids'])));
        if (! $needsPool || ! $exam->course_id) {
            return collect();
        }

        return Question::with('options')->where('course_id', $exam->course_id)->whereIn('status', $exam->category === 'practice' ? ['approved', 'draft', 'in_review'] : ['approved'])->limit(5000)->get();
    }
}
