<?php

namespace App\Services\Exams;

use App\Models\CourseOffering;
use App\Models\Exam;
use App\Support\Policy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may sit an exam (Target 46, 56; LMS 72, 77): active status, course registration,
 * minimum class attendance, and for resits/supplementaries the result of the original exam.
 * Everything is computed in a few grouped queries so a 3,000-candidate list stays fast.
 */
class Eligibility
{
    /** @return array<int, array{0: string, 1: ?string}> user id => [eligible|ineligible, reason] */
    public function evaluate(Exam $exam, Collection $users): array
    {
        $ids = $users->pluck('id')->all();
        $offering = $this->offering($exam);
        $enrolled = $offering ? DB::table('enrolments')->where('course_offering_id', $offering->id)->where('status', 'active')->whereIn('user_id', $ids)->pluck('user_id')->flip() : null;
        $attendance = $this->attendance($offering, $ids);
        $min = Policy::get('min_attendance_pct');
        $parent = $exam->kind !== 'regular' && $exam->parent_exam_id ? $this->parentOutcomes($exam, $ids) : null;

        $out = [];
        foreach ($users as $u) {
            $pct = $attendance[$u->id] ?? null;
            $out[$u->id] = match (true) {
                $u->status !== 'active' => ['ineligible', "Student status is {$u->status}"],
                $enrolled !== null && ! isset($enrolled[$u->id]) && $parent === null => ['ineligible', 'Not registered for this course'],
                $min !== null && $attendance !== null && ($pct ?? 0) < $min => ['ineligible', 'Class attendance '.($pct ?? 0)."% is below the required {$min}%"],
                $parent !== null && ! $this->parentAllows($exam->kind, $parent[$u->id] ?? null) => ['ineligible', $exam->kind === 'resit'
                    ? 'Passed the original exam, so no resit is needed' : 'Sat and passed the original exam'],
                default => ['eligible', null],
            };
        }

        return $out;
    }

    /** Students who should be offered the resit or supplementary of this exam. */
    public function parentCandidates(Exam $exam): Collection
    {
        abort_unless($exam->parent_exam_id, 422, 'This is not a resit or supplementary exam.');
        $parent = Exam::findOrFail($exam->parent_exam_id);
        $sat = DB::table('results')->where('exam_id', $parent->id)->whereNotIn('status', ['withheld'])->get(['user_id', 'final_score']);
        $expected = DB::table('exam_candidates as c')->join('exam_sessions as s', 's.id', '=', 'c.exam_session_id')->where('s.exam_id', $parent->id)->where('s.is_practice', false)->pluck('c.user_id')->unique();
        $pass = (float) ($parent->pass_mark ?? 0);
        $failed = $sat->filter(fn ($r) => $r->final_score === null || (float) $r->final_score < $pass)->pluck('user_id');
        $absent = $expected->diff($sat->pluck('user_id'));

        return ($exam->kind === 'resit' ? $failed : $failed->merge($absent))->unique()->values();
    }

    private function parentAllows(string $kind, ?array $outcome): bool
    {
        if ($outcome === null) {
            return $kind === 'supplementary'; // never sat: supplementary only
        }

        return ! $outcome['passed'];
    }

    private function parentOutcomes(Exam $exam, array $ids): array
    {
        $parent = Exam::find($exam->parent_exam_id);
        $pass = (float) ($parent?->pass_mark ?? 0);

        return DB::table('results')->where('exam_id', $exam->parent_exam_id)->whereIn('user_id', $ids)->get(['user_id', 'final_score'])
            ->mapWithKeys(fn ($r) => [$r->user_id => ['passed' => $r->final_score !== null && (float) $r->final_score >= $pass]])->all();
    }

    public function offering(Exam $exam): ?CourseOffering
    {
        return $exam->course_id && $exam->semester_id
            ? CourseOffering::where('course_id', $exam->course_id)->where('semester_id', $exam->semester_id)->first() : null;
    }

    /** Attendance percentage per student, or null when the course has no recorded classes. */
    public function attendance(?CourseOffering $offering, array $ids): ?array
    {
        if (! $offering) {
            return null;
        }
        $meetings = DB::table('class_meetings')->where('course_offering_id', $offering->id)->where('held_at', '<=', now())->pluck('id');
        if ($meetings->isEmpty()) {
            return null;
        }

        return DB::table('attendance_records')->whereIn('class_meeting_id', $meetings)->whereIn('user_id', $ids)->whereIn('status', ['present', 'late', 'excused'])
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) n')->pluck('n', 'user_id')
            ->map(fn ($n) => (int) round($n / $meetings->count() * 100))->all() + array_fill_keys($ids, 0);
    }
}
