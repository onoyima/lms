<?php

namespace App\Services\Proctoring;

use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\ProctorAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Live proctoring in batches. A sitting is split into batches of N candidates (in seat, then
 * matric-number order); each batch has its own live-video room, so a supervisor's screen and the
 * media server only carry the batch being watched. Supervisors can be given particular batches;
 * the chief invigilator and the exams office can open any batch. Recording does not depend on
 * batches: every candidate is recorded whether or not anyone is watching.
 */
class ProctorBatches
{
    public const SIZES = [10, 20, 30, 50, 60, 100, 150, 200, 300, 500];

    /** Split (or re-split) the sitting into batches of $size; null puts everyone in one group. */
    public function resize(ExamSession $session, ?int $size): void
    {
        DB::transaction(function () use ($session, $size) {
            $session->forceFill(['proctor_batch_size' => $size])->save();
            if (! $size) {
                ExamCandidate::where('exam_session_id', $session->id)->update(['proctor_batch' => null]);
                ProctorAssignment::where('exam_session_id', $session->id)->update(['batches' => null]);

                return;
            }
            $ordered = ExamCandidate::query()->where('exam_candidates.exam_session_id', $session->id)
                ->leftJoin('seats', 'seats.id', '=', 'exam_candidates.seat_id')
                ->leftJoin('users', 'users.id', '=', 'exam_candidates.user_id')
                ->orderByRaw('seats.label IS NULL, LENGTH(seats.label), seats.label')
                ->orderBy('users.identifier')->orderBy('exam_candidates.id')
                ->pluck('exam_candidates.id')->values();
            foreach ($ordered->chunk($size) as $i => $ids) {
                ExamCandidate::whereIn('id', $ids->all())->update(['proctor_batch' => $i + 1]);
            }
            // batches given to supervisors that no longer exist are dropped
            $last = max(1, (int) ceil($ordered->count() / $size));
            ProctorAssignment::where('exam_session_id', $session->id)->whereNotNull('batches')->get()
                ->each(fn ($p) => $p->update(['batches' => array_values(array_filter((array) $p->batches, fn ($b) => $b >= 1 && $b <= $last)) ?: null]));
        });
        // candidates' browsers learn their new batch on the next check-in
        ExamCandidate::where('exam_session_id', $session->id)->pluck('id')->each(fn ($id) => \Illuminate\Support\Facades\Cache::forget("cand:{$id}:batch"));
    }

    /** A candidate added after the batches were made joins the last batch, or starts a new one. */
    public function place(ExamCandidate $candidate): void
    {
        $size = (int) ExamSession::whereKey($candidate->exam_session_id)->value('proctor_batch_size');
        if (! $size || $candidate->proctor_batch) {
            return;
        }
        $last = (int) ExamCandidate::where('exam_session_id', $candidate->exam_session_id)->max('proctor_batch');
        $inLast = $last ? ExamCandidate::where('exam_session_id', $candidate->exam_session_id)->where('proctor_batch', $last)->count() : $size;
        $candidate->forceFill(['proctor_batch' => $inLast < $size ? $last : $last + 1])->saveQuietly();
    }

    /** Every batch: number, size, first and last candidate, and the supervisors given it. */
    public function list(ExamSession $session): array
    {
        if (! $session->proctor_batch_size) {
            return [];
        }
        $rows = ExamCandidate::where('exam_candidates.exam_session_id', $session->id)->whereNotNull('proctor_batch')
            ->leftJoin('users', 'users.id', '=', 'exam_candidates.user_id')
            ->leftJoin('attempts', fn ($j) => $j->on('attempts.exam_candidate_id', '=', 'exam_candidates.id')->whereIn('attempts.status', ['in_progress', 'paused', 'locked']))
            ->groupBy('exam_candidates.proctor_batch')
            ->selectRaw('exam_candidates.proctor_batch AS n, COUNT(DISTINCT exam_candidates.id) AS total, COUNT(DISTINCT attempts.id) AS writing, MIN(users.identifier) AS first, MAX(users.identifier) AS last')
            ->orderBy('n')->get();
        $who = [];
        ProctorAssignment::where('exam_session_id', $session->id)->with('user:id,name')->get()
            ->each(function ($p) use (&$who) {
                foreach ((array) $p->batches as $b) {
                    $who[(int) $b][] = ['id' => $p->user_id, 'name' => $p->user?->name];
                }
            });

        return $rows->map(fn ($r) => ['n' => (int) $r->n, 'total' => (int) $r->total, 'writing' => (int) $r->writing, 'first' => $r->first, 'last' => $r->last, 'supervisors' => $who[(int) $r->n] ?? []])->all();
    }

    /**
     * The batches this person may watch: null means any batch (exams office, chief invigilator,
     * or a supervisor without particular batches), otherwise the list they were given.
     */
    public function allowedFor(User $user, ExamSession $session, ?ProctorAssignment $assignment): ?array
    {
        if (! $assignment || $user->can('exams.manage') || in_array($assignment->role, ['chief', 'supervisor'], true)) {
            return null;
        }
        $given = array_map('intval', (array) $assignment->batches);

        return $given ?: null;
    }

    public function mayWatch(User $user, ExamSession $session, ?ProctorAssignment $assignment, ?int $batch): bool
    {
        $allowed = $this->allowedFor($user, $session, $assignment);

        return $allowed === null || ($batch !== null && in_array($batch, $allowed, true));
    }

    /** Give batches to the session's proctors: [user_id => [batch numbers]]. */
    public function assign(ExamSession $session, array $map): void
    {
        DB::transaction(function () use ($session, $map) {
            foreach (ProctorAssignment::where('exam_session_id', $session->id)->get() as $p) {
                if (array_key_exists($p->user_id, $map)) {
                    $list = array_values(array_unique(array_filter(array_map('intval', (array) $map[$p->user_id]), fn ($b) => $b > 0)));
                    sort($list);
                    $p->update(['batches' => $list ?: null]);
                }
            }
        });
    }
}
