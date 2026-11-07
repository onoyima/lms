<?php

namespace App\Services\Exams;

use App\Models\Appeal;
use App\Models\Approval;
use App\Models\Exam;
use App\Models\ExamVersion;
use App\Models\Result;
use App\Models\ResultChange;
use App\Models\User;
use App\Notifications\ResultsPublished;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Separation of duties for papers and results (Target 49, 61, 83-87, 149-162, 170):
 * a setter submits, a different person approves; results are moderated, approved and
 * published by authorised officers; any change to an approved result needs a second person.
 */
class ExamWorkflow
{
    public function __construct(private AuditLogger $audit, private Marker $marker) {}

    public function submitForApproval(Exam $exam, User $by): void
    {
        abort_unless(in_array($exam->status, ['draft', 'returned'], true), 422, 'Only a draft can be submitted.');
        $this->assertHasContent($exam);
        $exam->update(['status' => 'submitted']);
        $this->decide($exam, 'submission', $by, 'approved', null);
    }

    public function approve(Exam $exam, User $by, ?string $comment = null): ExamVersion
    {
        abort_unless($exam->status === 'submitted', 422, 'Only a submitted paper can be approved.');
        abort_if($exam->created_by === $by->id, 403, 'The setter cannot approve their own paper.');

        return DB::transaction(function () use ($exam, $by, $comment) {
            $snapshot = $this->snapshot($exam);
            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $version = ExamVersion::create([
                'exam_id' => $exam->id, 'version' => $exam->version, 'snapshot' => $snapshot,
                'checksum' => hash('sha256', $json), 'created_by' => $by->id, 'created_at' => now(),
            ]);
            $exam->update(['status' => 'scheduled', 'approved_at' => now(), 'approved_by' => $by->id]);
            $this->decide($exam, 'approval', $by, 'approved', $comment);

            return $version;
        });
    }

    public function returnForChanges(Exam $exam, User $by, string $comment): void
    {
        abort_unless($exam->status === 'submitted', 422);
        $exam->update(['status' => 'returned']);
        $this->decide($exam, 'approval', $by, 'returned', $comment);
    }

    /** Editing an approved paper starts a new version that must be approved again. */
    public function reopenForEditing(Exam $exam, User $by): void
    {
        abort_if($exam->attempts()->exists(), 422, 'Candidates have already sat this paper; create a new exam instead.');
        $exam->update(['status' => 'draft', 'version' => $exam->version + 1, 'approved_at' => null, 'approved_by' => null]);
        $this->audit->record('exam.reopened', $exam, ['version' => $exam->version], $by->id);
    }

    // ── Results ──────────────────────────────────────────────────────────────

    /** @param list<int>|null $resultIds null = all results of the exam in the source status */
    public function moveResults(Exam $exam, string $to, User $by, ?array $resultIds = null, ?string $comment = null): int
    {
        $from = ['moderated' => ['draft'], 'approved' => ['moderated'], 'published' => ['approved']][$to] ?? abort(422);
        $query = Result::where('exam_id', $exam->id)->whereIn('status', $from)
            ->when($resultIds, fn ($q) => $q->whereIn('id', $resultIds));
        $ids = $query->pluck('id');

        DB::transaction(function () use ($ids, $to, $by, $comment, $exam) {
            Result::whereIn('id', $ids)->update([
                'status' => $to,
                'locked' => in_array($to, ['approved', 'published'], true),
                'published_at' => $to === 'published' ? now() : null,
            ]);
            foreach (Result::whereIn('id', $ids)->get() as $r) {
                $this->decide($r, $to, $by, 'approved', $comment);
            }
            if ($to === 'published') {
                $exam->update(['status' => 'results_published']);
            }
        });

        if ($to === 'published' && $ids->isNotEmpty()) {
            $users = User::whereIn('id', Result::whereIn('id', $ids)->pluck('user_id'))->get();
            Notification::send($users, new ResultsPublished($exam));
        }
        $this->audit->record("results.$to", $exam, ['count' => $ids->count()], $by->id);

        return $ids->count();
    }

    public function withhold(Result $result, User $by, string $reason): void
    {
        $result->update(['status' => 'withheld', 'withheld_reason' => $reason]);
        $this->audit->record('result.withheld', $result, ['reason' => $reason], $by->id);
    }

    public function requestChange(Result $result, User $by, float $to, string $reason): ResultChange
    {
        abort_if($result->max_score !== null && $to > ($result->exam->total_mark ?? $result->max_score), 422, 'Score is above the maximum.');

        $change = ResultChange::create([
            'result_id' => $result->id, 'from_score' => $result->final_score, 'to_score' => $to,
            'reason' => $reason, 'requested_by' => $by->id,
        ]);
        $this->audit->record('result.change_requested', $result, ['from' => $result->final_score, 'to' => $to, 'reason' => $reason], $by->id);

        return $change;
    }

    public function decideChange(ResultChange $change, User $by, bool $approve): void
    {
        abort_unless($change->status === 'pending', 422);
        abort_if($change->requested_by === $by->id, 403, 'A different officer must approve this change.');

        DB::transaction(function () use ($change, $by, $approve) {
            $change->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by->id, 'decided_at' => now()]);
            if ($approve) {
                $result = $change->result;
                $outOf = (float) ($result->exam->total_mark ?: $result->max_score ?: 100);
                $result->update([
                    'final_score' => $change->to_score,
                    'grade' => $this->marker->grade($outOf > 0 ? $change->to_score / $outOf * 100 : 0),
                    'version' => $result->version + 1,
                ]);
            }
            $this->audit->record('result.change_'.($approve ? 'approved' : 'rejected'), $change->result, ['change' => $change->id, 'to' => $change->to_score], $by->id);
        });
    }

    public function decideAppeal(Appeal $appeal, User $by, bool $upheld, string $decision): void
    {
        $appeal->update(['status' => $upheld ? 'upheld' : 'rejected', 'decision' => $decision, 'decided_by' => $by->id, 'decided_at' => now()]);
        $this->audit->record('appeal.decided', $appeal, ['upheld' => $upheld], $by->id);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function decide($model, string $step, User $by, string $decision, ?string $comment): void
    {
        Approval::create([
            'approvable_type' => $model->getMorphClass(), 'approvable_id' => $model->getKey(),
            'step' => $step, 'user_id' => $by->id, 'decision' => $decision, 'comment' => $comment, 'created_at' => now(),
        ]);
        $this->audit->record($model->getTable().'.'.$step.'.'.$decision, $model, array_filter(['comment' => $comment]), $by->id);
    }

    private function assertHasContent(Exam $exam): void
    {
        if ($exam->isPanelMarked()) {
            abort_if($exam->criteria()->count() === 0, 422, 'Add at least one marking criterion first.');

            return;
        }
        $has = $exam->sections()->withCount('questions')->get()->sum(fn ($s) => $s->questions_count + collect($s->blueprint ?? [])->sum('count'));
        abort_if($has === 0, 422, 'Add questions or blueprint rules first.');
    }

    private function snapshot(Exam $exam): array
    {
        $exam->load(['sections.questions.options', 'criteria']);

        return [
            'exam' => $exam->only(['id', 'title', 'type', 'duration_minutes', 'total_mark', 'pass_mark', 'max_attempts', 'score_policy', 'settings', 'proctoring', 'channels', 'version']),
            'sections' => $exam->sections->map(fn ($s) => [
                'title' => $s->title, 'blueprint' => $s->blueprint,
                'questions' => $s->questions->map(fn ($q) => ['id' => $q->id, 'version' => $q->version] + $q->snapshot())->all(),
            ])->all(),
            'criteria' => $exam->criteria->map(fn ($c) => $c->only(['id', 'text', 'allowed_scores', 'max_score']))->all(),
        ];
    }
}
