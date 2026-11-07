<?php

namespace App\Services\Proctoring;

use App\Models\ProctorAssignment;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Notification;

/**
 * Proctor disconnection alerts (Target 78): when a proctor's console stops checking in during
 * a live sitting, the chief invigilator (or, if there is none, the exams office) is told at once,
 * so their candidates are covered. One alert per disconnection; it re-arms when they return.
 */
class ProctorWatch
{
    public const OFFLINE_SECONDS = 120;

    public function __construct(private AuditLogger $audit) {}

    public function run(): int
    {
        $now = now();
        $offline = ProctorAssignment::query()
            ->join('exam_sessions as s', 's.id', '=', 'proctor_assignments.exam_session_id')
            ->where('s.tenant_id', tenant_id())->where('s.is_practice', false)
            ->whereIn('s.status', ['open', 'paused'])->where('s.starts_at', '<', $now->copy()->subMinutes(3))->where('s.ends_at', '>', $now)
            ->where('proctor_assignments.role', '!=', 'chief')
            ->where(fn ($q) => $q->whereNull('proctor_assignments.last_seen_at')->orWhere('proctor_assignments.last_seen_at', '<', $now->copy()->subSeconds(self::OFFLINE_SECONDS)))
            // alert once per disconnection: re-arm only after the proctor has been seen again
            ->where(fn ($q) => $q->whereNull('proctor_assignments.offline_alerted_at')->orWhereColumn('proctor_assignments.offline_alerted_at', '<', 'proctor_assignments.last_seen_at'))
            ->with(['user:id,name', 'session.exam:id,title'])->get(['proctor_assignments.*']);

        foreach ($offline as $a) {
            $chiefs = User::whereIn('id', ProctorAssignment::where('exam_session_id', $a->exam_session_id)->where('role', 'chief')->pluck('user_id'))->get();
            $to = $chiefs->isNotEmpty() ? $chiefs : User::permission('exams.manage')->where('status', 'active')->get();
            $candidates = \DB::table('proctor_candidate')->where('proctor_assignment_id', $a->id)->count();
            Notification::send($to, new LmsNotice(
                'Proctor offline: '.$a->user?->name,
                "{$a->user?->name}'s console stopped responding during {$a->session->exam->title}".($a->last_seen_at ? ' (last seen '.$a->last_seen_at->format('H:i').')' : ' (never connected)').
                ". $candidates candidates are not being watched — reassign them from the console.",
                "/proctor/sessions/{$a->exam_session_id}", true, category: 'exams'));
            $a->forceFill(['offline_alerted_at' => $now])->saveQuietly();
            $this->audit->record('proctor.offline_alert', $a->session, ['proctor' => $a->user_id, 'candidates' => $candidates, 'notified' => $to->pluck('id')->all()]);
        }

        return $offline->count();
    }
}
