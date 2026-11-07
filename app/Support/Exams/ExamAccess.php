<?php

namespace App\Support\Exams;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may work on an exam. The exams office (exams.manage) sees every exam. Any other member of
 * staff sees only the exams assigned to them: named as an examiner on the exam, or teaching the
 * exam's course (in the exam's semester when it has one). On those exams they can monitor the
 * sittings, add questions and students, see the statistics and download and rescale the results.
 */
class ExamAccess
{
    public static function managesAll(User $user): bool
    {
        return $user->can('exams.manage');
    }

    /** Limit an exam query to what this person may see. */
    public static function scope(Builder $query, User $user): Builder
    {
        if (self::managesAll($user)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->whereIn('exams.id', DB::table('exam_examiners')->where('user_id', $user->id)->select('exam_id'))
                ->orWhereExists(function ($sub) use ($user) {
                    $sub->selectRaw('1')->from('course_offerings')
                        ->join('course_offering_staff', 'course_offering_staff.course_offering_id', '=', 'course_offerings.id')
                        ->where('course_offering_staff.user_id', $user->id)
                        ->whereColumn('course_offerings.course_id', 'exams.course_id')
                        ->where(fn ($w) => $w->whereNull('exams.semester_id')->orWhereColumn('course_offerings.semester_id', 'exams.semester_id'));
                });
        });
    }

    public static function can(User $user, Exam $exam): bool
    {
        return self::managesAll($user) || self::scope(Exam::query()->whereKey($exam->id), $user)->exists();
    }

    public static function authorize(User $user, Exam $exam): void
    {
        abort_unless(self::can($user, $exam), 403, 'This exam is not assigned to you.');
    }

    /** Does this person have any exam to look after? (for the menu) */
    public static function hasAny(User $user): bool
    {
        return self::managesAll($user) || ($user->user_type === 'staff' && self::scope(Exam::query(), $user)->exists());
    }
}
