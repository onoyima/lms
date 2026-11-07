<?php

namespace App\Conferencing;

use App\Models\Meeting;
use App\Models\User;
use App\Services\Lms\LmsAccess;

/**
 * Who may see, join and run a meeting — checked on the server for every action.
 *  - Runs it (joins as moderator): the creator, people given host / co-host / examiner / proctor, the course's staff,
 *    and anyone with conferencing.manage.
 *  - Joins it: invited people, students enrolled in the course (course meetings), anyone in the institution (open meetings).
 */
class MeetingAccess
{
    public function __construct(private LmsAccess $lms) {}

    public function role(User $user, Meeting $m): ?string
    {
        return $m->participants->firstWhere('user_id', $user->id)?->role;
    }

    public function canManageAll(User $user): bool
    {
        return $user->can('conferencing.manage');
    }

    public function moderates(User $user, Meeting $m): bool
    {
        if ($m->created_by === $user->id || $this->canManageAll($user) || in_array($this->role($user, $m), Meeting::MODERATOR_ROLES, true)) {
            return true;
        }

        if ($this->linkedExam($m)?->examiners()->where('users.id', $user->id)->exists()) {
            return true; // examiners of the viva or defence held in this room
        }

        return $m->offering && $this->lms->teaches($user, $m->offering);
    }

    /** The viva, practical or research exam this meeting is for, if any. */
    public function linkedExam(Meeting $m): ?\App\Models\Exam
    {
        $id = $m->setting('exam_id');

        return $id ? \App\Models\Exam::find($id) : null;
    }

    /** Candidates of the linked exam may join its room. */
    public function isCandidate(User $user, Meeting $m): bool
    {
        $exam = $this->linkedExam($m);

        return $exam && \App\Models\ExamCandidate::whereIn('exam_session_id', $exam->sessions()->pluck('id'))->where('user_id', $user->id)->exists();
    }

    /** The creator, conferencing managers and full hosts may change the meeting itself (co-hosts run it but do not edit it). */
    public function edits(User $user, Meeting $m): bool
    {
        return $m->created_by === $user->id || $this->canManageAll($user) || $this->role($user, $m) === 'host'
            || ($m->offering && $this->lms->canGrade($user, $m->offering));
    }

    public function joins(User $user, Meeting $m): bool
    {
        if ($this->moderates($user, $m) || $this->role($user, $m) !== null || $this->isCandidate($user, $m)) {
            return true;
        }

        return match ($m->access) {
            'course' => $m->offering && $this->lms->learns($user, $m->offering),
            'institution' => $user->tenant_id === $m->tenant_id && $user->status === 'active',
            default => false,
        };
    }

    public function canCreate(User $user): bool
    {
        return $user->can('meetings.host') || $this->canManageAll($user) || \Illuminate\Support\Facades\DB::table('course_offering_staff')->where('user_id', $user->id)->exists();
    }
}
