<?php

namespace App\Services\Exams;

use App\Models\Attempt;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionReport;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Settling students' reports of a faulty question, for everyone who had it on this exam:
 *  - full_mark: everyone gets the question's full mark;
 *  - drop:      the question is taken out (it counts for nobody, the total goes down);
 *  - fix_key:   the right answer is corrected and everyone is marked again;
 *  - dismiss:   the question stands.
 * Done before results are approved; afterwards scores change only through result changes.
 */
class QuestionReports
{
    public const ACTIONS = ['full_mark', 'drop', 'fix_key', 'dismiss'];

    public function __construct(private Marker $marker, private AuditLogger $audit) {}

    /** Reports grouped by question, for the exam page. */
    public function summary(Exam $exam): array
    {
        $reports = QuestionReport::with(['question.options', 'user:id,name,identifier'])->where('exam_id', $exam->id)->latest('id')->get();

        return $reports->groupBy('question_id')->map(function ($group) {
            $q = $group->first()->question;

            return [
                'question_id' => $q?->id, 'type' => $q?->type, 'stem_html' => $q?->stem_html,
                'options' => $q ? $q->options->map(fn ($o) => ['id' => $o->id, 'html' => $o->body_html, 'correct' => (bool) $o->is_correct])->values() : [],
                'count' => $group->count(), 'open' => $group->where('status', 'open')->count(),
                'status' => $group->contains('status', 'open') ? 'open' : $group->first()->status,
                'resolution' => $group->firstWhere('resolution')?->resolution, 'response' => $group->firstWhere('response')?->response,
                'reasons' => $group->countBy('reason'),
                'notes' => $group->filter(fn ($r) => $r->note)->take(50)->map(fn ($r) => ['note' => $r->note, 'reason' => $r->reason, 'by' => $r->user?->identifier ?? $r->user?->name])->values(),
            ];
        })->sortByDesc('open')->values()->all();
    }

    /** @param list<int> $correct option ids that are right (fix_key); for multiple true/false, the true statements */
    public function settle(Exam $exam, int $questionId, string $action, ?string $response, User $by, array $correct = []): int
    {
        abort_unless(in_array($action, self::ACTIONS, true), 422);
        abort_if($action !== 'dismiss' && \App\Models\Result::where('exam_id', $exam->id)->where('locked', true)->exists(), 422,
            'Results for this exam are approved, so scores are locked. Change a score through Results › Changes instead.');

        $remarked = DB::transaction(function () use ($exam, $questionId, $action, $correct) {
            if ($action === 'dismiss') {
                return 0;
            }
            $rows = DB::table('attempt_questions')->join('attempts', 'attempts.id', '=', 'attempt_questions.attempt_id')
                ->where('attempts.exam_id', $exam->id)->where('attempt_questions.question_id', $questionId)
                ->get(['attempt_questions.id', 'attempt_questions.key', 'attempt_questions.attempt_id']);
            abort_if($rows->isEmpty(), 422, 'Nobody has had this question yet.');
            $question = Question::withTrashed()->with('options')->findOrFail($questionId);

            if ($action === 'fix_key') {
                abort_unless(in_array($question->type, ['single_choice', 'multiple_choice', 'true_false', 'multi_true_false'], true), 422, 'Only objective questions can have their answer corrected here; mark the others by hand.');
                $ids = $question->options->pluck('id')->map(fn ($v) => (int) $v)->all();
                $correct = array_values(array_intersect($ids, array_map('intval', $correct)));
                abort_if($question->type !== 'multi_true_false' && $correct === [], 422, 'Choose the right option.');
                abort_if(in_array($question->type, ['single_choice', 'true_false'], true) && count($correct) > 1, 422, 'This question has one right option.');
                // the question itself is corrected too, so later papers are right
                foreach ($question->options as $o) {
                    $o->update(['is_correct' => in_array((int) $o->id, $correct, true)]);
                }
                $question->increment('version');
            }
            foreach ($rows as $row) {
                $key = json_decode($row->key ?? 'null', true) ?: [];
                $key = match ($action) {
                    'full_mark' => ['award' => 'all'] + $key,
                    'drop' => ['dropped' => true] + $key,
                    'fix_key' => $question->type === 'multi_true_false'
                        ? ['tf' => collect($question->options)->mapWithKeys(fn ($o) => [(string) $o->id => in_array((int) $o->id, $correct, true)])->all()] + \Illuminate\Support\Arr::except($key, ['tf', 'award', 'dropped'])
                        : ['options' => $correct] + \Illuminate\Support\Arr::except($key, ['options', 'award', 'dropped']),
                };
                DB::table('attempt_questions')->where('id', $row->id)->update(['key' => json_encode($key)]);
            }
            $attempts = Attempt::whereIn('id', $rows->pluck('attempt_id'))->whereIn('status', Attempt::CLOSED)->get();
            foreach ($attempts as $a) {
                $this->marker->markAttempt($a);
            }

            return $attempts->count();
        });

        QuestionReport::where('exam_id', $exam->id)->where('question_id', $questionId)->update([
            'status' => $action === 'dismiss' ? 'dismissed' : 'resolved', 'resolution' => $action, 'response' => $response,
            'resolved_by' => $by->id, 'resolved_at' => now(),
        ]);
        $this->audit->record('question_report.'.$action, $exam, ['question' => $questionId, 'attempts_remarked' => $remarked, 'correct' => $correct ?: null]);

        return $remarked;
    }
}
