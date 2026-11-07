<?php

namespace App\Services\Exams;

use App\Models\ExamSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Timetable clash detection (LMS 77; Target 48, 200): candidates sitting two exams whose
 * times overlap, and venues booked twice. One indexed self-join, so it stays fast for a
 * whole examination period.
 */
class TimetableClashes
{
    /** Candidates in this sitting who are also in another overlapping sitting. */
    public function forSession(ExamSession $session): Collection
    {
        return DB::table('exam_candidates as a')
            ->join('exam_candidates as b', fn ($j) => $j->on('a.user_id', '=', 'b.user_id')->on('a.exam_session_id', '!=', 'b.exam_session_id'))
            ->join('exam_sessions as s2', 's2.id', '=', 'b.exam_session_id')
            ->join('exams as e2', 'e2.id', '=', 's2.exam_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.exam_session_id', $session->id)
            ->where('s2.starts_at', '<', $session->ends_at)->where('s2.ends_at', '>', $session->starts_at)
            ->where('s2.is_practice', false)->whereNotIn('e2.category', ['quiz', 'practice'])
            ->limit(500)->get(['u.id as user_id', 'u.name', 'u.identifier', 'e2.title as other_exam', 's2.starts_at as other_starts_at', 's2.id as other_session_id']);
    }

    /** All clashes between two dates, for the exams office. */
    public function between(string $from, string $to): array
    {
        $candidates = DB::table('exam_candidates as a')
            ->join('exam_sessions as s1', 's1.id', '=', 'a.exam_session_id')
            ->join('exam_candidates as b', fn ($j) => $j->on('a.user_id', '=', 'b.user_id')->on('a.exam_session_id', '<', 'b.exam_session_id'))
            ->join('exam_sessions as s2', 's2.id', '=', 'b.exam_session_id')
            ->where('s1.tenant_id', app(\App\Support\Tenancy\TenantContext::class)->id())
            ->whereBetween('s1.starts_at', [$from, $to])
            ->whereColumn('s2.starts_at', '<', 's1.ends_at')->whereColumn('s2.ends_at', '>', 's1.starts_at')
            ->where('s1.is_practice', false)->where('s2.is_practice', false)
            ->selectRaw('s1.id as session_a, s2.id as session_b, COUNT(*) as candidates')->groupBy('s1.id', 's2.id')->get();

        $venues = DB::table('exam_sessions as s1')
            ->join('exam_sessions as s2', fn ($j) => $j->on('s1.venue_id', '=', 's2.venue_id')->on('s1.id', '<', 's2.id'))
            ->where('s1.tenant_id', app(\App\Support\Tenancy\TenantContext::class)->id())
            ->whereNotNull('s1.venue_id')->whereBetween('s1.starts_at', [$from, $to])
            ->whereColumn('s2.starts_at', '<', 's1.ends_at')->whereColumn('s2.ends_at', '>', 's1.starts_at')
            ->get(['s1.id as session_a', 's2.id as session_b', 's1.venue_id']);

        return ['candidates' => $candidates, 'venues' => $venues];
    }
}
