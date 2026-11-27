<?php

namespace App\Http\Controllers\Results;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Result;
use App\Services\Analytics\QuestionStats;
use App\Services\Audit\AuditLogger;
use App\Services\Exams\ExamWorkflow;
use App\Services\Reports\Exporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Results question by question, as the old exam system had them: statistics per question, the
 * Q1…Qn score sheet download, each student's script, and correcting one answer in a script.
 */
class QuestionResultsController extends Controller
{
    public function __construct(private QuestionStats $stats, private AuditLogger $audit) {}

    public function questions(Exam $exam): Response
    {
        return Inertia::render('results/questions', [
            'exam' => $exam->only(['id', 'title', 'type', 'status']),
            'questions' => $this->stats->stats($exam),
            'scripts' => $this->stats->attempts($exam)->sortBy(fn ($a) => mb_strtolower((string) $a->user?->name))->values()
                ->map(fn ($a) => ['attempt_id' => $a->id, 'name' => $a->user?->name, 'identifier' => $a->user?->identifier, 'raw' => (float) $a->score_raw, 'max' => (float) $a->score_max]),
        ]);
    }

    /** Statistics per question as a spreadsheet. */
    public function questionsExport(Request $request, Exam $exam, Exporter $exporter)
    {
        $rows = collect($this->stats->stats($exam))->map(fn ($q) => [
            'Q'.$q['n'], trim(html_entity_decode(strip_tags((string) $q['stem_html']))), $q['type'], $q['topic'], $q['mark'], $q['seen'], $q['answered'], $q['full_marks'],
            $q['full_percent'], $q['average'], $q['facility'], $q['discrimination'],
            collect($q['options'])->map(fn ($o) => $o['label'].($o['correct'] ? '*' : '').': '.$o['count'].' ('.$o['percent'].'%)')->implode('; ')
                ?: collect($q['statements'])->map(fn ($s) => $s['label'].') '.$s['percent'].'% right')->implode('; '),
            $q['reports'],
        ]);
        $this->audit->record('results.question_stats_exported', $exam, ['rows' => $rows->count()]);
        $columns = ['Question', 'Text', 'Type', 'Topic', 'Mark', 'Students', 'Answered', 'Full marks', '% full marks', 'Average mark', 'Facility %', 'Discrimination', 'Options chosen (* = right)', 'Reports'];
        $name = 'question-statistics-'.$exam->id;

        return $request->query('format') === 'csv' ? $exporter->csv($name, $columns, $rows->all()) : $exporter->xlsx($name, 'Questions', $columns, $rows->all());
    }

    /** The old system's per-question score sheet: names split, Q1…Qn, total, out of, time taken, status. */
    public function scoreSheet(Request $request, Exam $exam, string $format, Exporter $exporter)
    {
        [$head, $rows] = $this->stats->scoreSheet($exam);
        $this->audit->record('results.score_sheet_exported', $exam, ['rows' => count($rows), 'format' => $format]);
        $name = 'report_'.preg_replace('/[^A-Za-z0-9_-]+/', '_', $exam->title);

        return $format === 'csv' ? $exporter->csv($name, $head, $rows) : $exporter->xlsx($name, 'Scores', $head, $rows);
    }

    /** One student's script: every question as they had it, what they chose, the right answer and the mark. */
    public function script(Exam $exam, Attempt $attempt): Response
    {
        abort_unless($attempt->exam_id === $exam->id, 404);
        $attempt->load('user:id,name,identifier');
        $numbers = $this->stats->questions($exam)->pluck('n', 'id');
        $rows = DB::table('attempt_questions as aq')
            ->leftJoin('attempt_answers as aa', fn ($j) => $j->on('aa.attempt_id', '=', 'aq.attempt_id')->on('aa.question_id', '=', 'aq.question_id'))
            ->where('aq.attempt_id', $attempt->id)->orderBy('aq.position')
            ->get(['aq.question_id', 'aq.position', 'aq.mark', 'aq.key', 'aq.option_order', 'aa.response', 'aa.score']);
        $questions = Question::withTrashed()->with('options')->whereIn('id', $rows->pluck('question_id'))->get()->keyBy('id');
        $result = Result::where('exam_id', $exam->id)->where('user_id', $attempt->user_id)->first();

        // the scripts before and after this one (by name), to go through them one after another
        $order = DB::table('attempts as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.exam_id', $exam->id)
            ->whereNotIn('a.status', ['in_progress', 'paused', 'locked', 'ending'])->orderBy('u.name')->orderBy('a.id')->pluck('a.id')->values();
        $at = $order->search($attempt->id);

        return Inertia::render('results/script', [
            'nav' => ['prev' => $at !== false && $at > 0 ? $order[$at - 1] : null, 'next' => $at !== false ? ($order[$at + 1] ?? null) : null,
                'position' => $at === false ? null : $at + 1, 'total' => $order->count()],
            'exam' => $exam->only(['id', 'title']),
            'attempt' => ['id' => $attempt->id, 'name' => $attempt->user?->name, 'identifier' => $attempt->user?->identifier, 'raw' => (float) $attempt->score_raw, 'max' => (float) $attempt->score_max,
                'status' => $attempt->status, 'submitted_at' => $attempt->submitted_at?->toIso8601String(), 'final' => $result?->final_score !== null ? (float) $result->final_score : null, 'locked' => (bool) $result?->locked],
            'items' => $rows->map(function ($r) use ($questions, $numbers) {
                $q = $questions[$r->question_id] ?? null;
                $key = json_decode($r->key ?? 'null', true) ?: [];
                $response = json_decode($r->response ?? 'null', true);
                $order = json_decode($r->option_order ?? '[]', true) ?: [];
                $options = $q ? collect($order ?: $q->options->pluck('id')->all())->map(fn ($id) => $q->options->firstWhere('id', $id))->filter()->values() : collect();

                return [
                    'question_id' => (int) $r->question_id, 'position' => (int) $r->position, 'n' => $numbers[$r->question_id] ?? null, 'type' => $q?->type, 'stem_html' => $q?->stem_html,
                    'mark' => (float) $r->mark, 'score' => $r->score !== null ? (float) $r->score : null, 'dropped' => (bool) ($key['dropped'] ?? false), 'award_all' => ($key['award'] ?? null) === 'all',
                    'options' => $options->values()->map(fn ($o, $i) => ['id' => $o->id, 'label' => chr(65 + $i), 'html' => $o->body_html,
                        'right' => in_array($o->id, (array) ($key['options'] ?? []), true), 'truth' => isset($key['tf'][(string) $o->id]) ? (bool) $key['tf'][(string) $o->id] : null,
                        'chosen' => in_array($o->id, array_map('intval', (array) ($response['options'] ?? [])), true),
                        'said' => isset($response['tf'][(string) $o->id]) ? (bool) $response['tf'][(string) $o->id] : null]),
                    'text' => is_array($response) ? ($response['text'] ?? (isset($response['number']) ? (string) $response['number'] : null)) : null,
                    'correctable' => in_array($q?->type, ['single_choice', 'multiple_choice', 'true_false', 'multi_true_false'], true),
                ];
            }),
            'pending' => \App\Models\ResultChange::where('result_id', $result?->id ?? 0)->where('status', 'pending')->whereNotNull('answer')->get()
                ->map(fn ($c) => ['question_id' => $c->answer['question_id'] ?? null, 'reason' => $c->reason]),
            'needsApproval' => (bool) \App\Support\Policy::get('result_change_approval') || ! request()->user()->can('results.approve'),
        ]);
    }

    /** Correct one answer in the script (what the student chose), with the reason; marked again on approval. */
    public function correctAnswer(Request $request, Exam $exam, Attempt $attempt, int $question, ExamWorkflow $workflow): RedirectResponse
    {
        abort_unless($attempt->exam_id === $exam->id, 404);
        abort_unless($request->user()->can('results.mark') || $request->user()->can('results.approve'), 403);
        $data = $request->validate([
            'options' => ['nullable', 'array', 'max:20'], 'options.*' => ['integer'],
            'tf' => ['nullable', 'array', 'max:20'], 'tf.*' => ['boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $row = DB::table('attempt_questions')->where('attempt_id', $attempt->id)->where('question_id', $question)->first();
        abort_unless($row, 404);
        $ids = array_map('intval', json_decode($row->option_order ?? '[]', true) ?: Question::withTrashed()->findOrFail($question)->options()->pluck('id')->all());
        $to = isset($data['tf'])
            ? ['tf' => collect($data['tf'])->filter(fn ($v, $k) => in_array((int) $k, $ids, true))->map(fn ($v) => (bool) $v)->all()]
            : ['options' => array_values(array_intersect($ids, array_map('intval', $data['options'] ?? [])))];
        $result = Result::where('exam_id', $exam->id)->where('user_id', $attempt->user_id)->firstOrFail();
        $number = $this->stats->questions($exam)->firstWhere('id', $question)['n'] ?? null;
        $change = $workflow->requestAnswerCorrection($result, $attempt, $question, (int) $number, $to, $request->user(), $data['reason']);

        return back()->with('success', $change->status === 'approved'
            ? 'Answer corrected and the script marked again. New score: '.$change->to_score.'.'
            : 'Correction sent. A second officer approves it on the results page; the script is marked again then.');
    }
}
