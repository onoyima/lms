<?php

namespace App\Services\Proctoring;

use App\Models\Attempt;
use App\Models\CandidateMessage;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\ProctorAssignment;
use App\Models\ProctoringEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Proctor training sandbox (Target 80, 205): a private practice sitting with simulated
 * candidates — one asking for help, one locked, risky behaviour, an offline device — so a
 * new proctor can practise on the real console without touching a real exam. Simulated
 * candidates are inactive accounts that can never sign in, and practice sittings are kept
 * out of results, reports and the timetable.
 */
class TrainingSandbox
{
    public const TASKS = [
        'ack_help' => 'Answer a help request',
        'warn' => 'Send a warning',
        'unlock' => 'Unlock a locked candidate',
        'incident' => 'Open an incident',
    ];

    public function open(User $proctor): ExamSession
    {
        return DB::transaction(function () use ($proctor) {
            $exam = Exam::firstOrCreate(['code' => 'PROCTOR-TRAINING'], [
                'title' => 'Proctor training sandbox', 'type' => 'cbt', 'category' => 'practice', 'status' => 'scheduled', 'duration_minutes' => 60, 'max_attempts' => 1,
                'total_mark' => 0, 'created_by' => $proctor->id, 'settings' => [], 'proctoring' => ['require_consent' => false], 'channels' => ['web'],
            ]);
            $session = ExamSession::create(['exam_id' => $exam->id, 'name' => 'Training · '.$proctor->name, 'mode' => 'remote', 'is_practice' => true, 'status' => 'open',
                'starts_at' => now()->subMinutes(5), 'ends_at' => now()->addHours(2)]);
            $assignment = ProctorAssignment::create(['exam_session_id' => $session->id, 'user_id' => $proctor->id, 'role' => 'proctor', 'max_candidates' => 50, 'last_seen_at' => now()]);
            DB::table('proctor_trainings')->insert(['tenant_id' => tenant_id(), 'user_id' => $proctor->id, 'exam_session_id' => $session->id, 'tasks' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);

            $scenarios = [
                ['Ada (simulated)', 'in_progress', 5, [], 'Please, my screen froze for a moment — will I lose my answers?'],
                ['Bayo (simulated)', 'locked', 72, [['tab_hidden', 3], ['tab_hidden', 3], ['face_missing', 4]], null],
                ['Chidi (simulated)', 'in_progress', 48, [['multiple_faces', 4], ['copy_attempt', 3]], null],
                ['Dami (simulated)', 'in_progress', 12, [['network_offline', 2]], null, true],
                ['Efe (simulated)', 'in_progress', 0, [], null],
                ['Funmi (simulated)', 'submitted', 0, [], null],
            ];
            foreach ($scenarios as $i => $sc) {
                [$name, $status, $risk, $events, $help] = $sc;
                $user = User::forceCreate(['name' => $name, 'email' => 'sandbox-'.Str::lower(Str::random(12)).'@training.invalid', 'password' => Str::password(40),
                    'user_type' => 'sandbox', 'status' => 'inactive', 'identifier' => 'TRAIN/'.Str::upper(Str::random(6))]);
                $candidate = ExamCandidate::create(['exam_session_id' => $session->id, 'user_id' => $user->id, 'attendance' => 'present', 'checked_in_at' => now()]);
                DB::table('proctor_candidate')->insert(['proctor_assignment_id' => $assignment->id, 'exam_candidate_id' => $candidate->id]);
                $attempt = Attempt::forceCreate(['tenant_id' => tenant_id(), 'exam_id' => $exam->id, 'exam_session_id' => $session->id, 'exam_candidate_id' => $candidate->id, 'user_id' => $user->id,
                    'attempt_no' => 1, 'status' => $status, 'channel' => 'web', 'started_at' => now()->subMinutes(4), 'deadline_at' => now()->addMinutes(56),
                    'paused_at' => $status === 'locked' ? now()->subMinute() : null, 'submitted_at' => $status === 'submitted' ? now() : null,
                    'last_seen_at' => ($sc[5] ?? false) ? now()->subMinutes(3) : now(), 'answered_count' => 3 + $i, 'violation_count' => count($events), 'risk_score' => $risk]);
                foreach ($events as [$type, $sev]) {
                    ProctoringEvent::create(['tenant_id' => tenant_id(), 'attempt_id' => $attempt->id, 'type' => $type, 'severity' => $sev, 'data' => ['simulated' => true], 'client_time' => now(), 'created_at' => now()]);
                }
                if ($help) {
                    CandidateMessage::create(['attempt_id' => $attempt->id, 'sender_id' => $user->id, 'direction' => 'to_proctor', 'kind' => 'help', 'body' => $help, 'created_at' => now()]);
                }
            }

            return $session;
        });
    }

    /** Record a practice task done on a sandbox sitting; complete the training when all are done. */
    public function record(ExamSession $session, int $userId, string $task): void
    {
        if (! $session->is_practice || ! isset(self::TASKS[$task])) {
            return;
        }
        $row = DB::table('proctor_trainings')->where('exam_session_id', $session->id)->where('user_id', $userId)->first();
        if (! $row) {
            return;
        }
        $tasks = array_values(array_unique([...json_decode($row->tasks ?? '[]', true), $task]));
        $done = count(array_intersect(array_keys(self::TASKS), $tasks)) === count(self::TASKS);
        DB::table('proctor_trainings')->where('id', $row->id)->update(['tasks' => json_encode($tasks), 'completed_at' => $row->completed_at ?? ($done ? now() : null), 'updated_at' => now()]);
    }

    /** Remove a finished sandbox and its simulated people. */
    public function close(ExamSession $session): void
    {
        abort_unless($session->is_practice && $session->exam->code === 'PROCTOR-TRAINING', 422);
        DB::transaction(function () use ($session) {
            $users = ExamCandidate::where('exam_session_id', $session->id)->pluck('user_id');
            $session->delete();
            User::whereIn('id', $users)->where('user_type', 'sandbox')->delete();
        });
    }
}
