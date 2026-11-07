<?php

namespace App\Services\Lms;

use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\RegistrationWindow;
use App\Models\Semester;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Course registration with the university's rules (LMS 15-24):
 * window (with late registration), student status, programme/level catalogue,
 * prerequisites, capacity, minimum/maximum credit units, add/drop, optional approval.
 */
class RegistrationService
{
    public function __construct(private AuditLogger $audit) {}

    public function window(Semester $semester): ?RegistrationWindow
    {
        return RegistrationWindow::where('semester_id', $semester->id)->first();
    }

    /** open, late, closed, none */
    public function windowState(?RegistrationWindow $w): string
    {
        $now = now();

        return match (true) {
            ! $w => 'none',
            $now->between($w->opens_at, $w->closes_at) => 'open',
            $w->late_closes_at && $now->between($w->closes_at, $w->late_closes_at) => 'late',
            default => 'closed',
        };
    }

    /** Offerings this student may choose this semester, each with a reason if blocked. */
    public function catalogue(User $student, Semester $semester): Collection
    {
        $profile = $student->studentProfile;
        $offerings = CourseOffering::with('course')->where('semester_id', $semester->id)->whereIn('status', ['open'])->get();
        $plan = $profile?->programme_id
            ? DB::table('programme_courses')->where('programme_id', $profile->programme_id)->get()->keyBy('course_id')
            : collect();
        $counts = DB::table('enrolments')->whereIn('course_offering_id', $offerings->pluck('id'))->whereIn('status', ['active', 'pending'])
            ->groupBy('course_offering_id')->selectRaw('course_offering_id, COUNT(*) n')->pluck('n', 'course_offering_id');
        $mine = DB::table('enrolments')->where('user_id', $student->id)->whereIn('course_offering_id', $offerings->pluck('id'))->pluck('status', 'course_offering_id');
        $passed = $this->passedCourseIds($student);
        $failed = $this->failedCourseIds($student);
        $prereqs = DB::table('course_prerequisites')->whereIn('course_id', $offerings->pluck('course_id'))->get()->groupBy('course_id');

        return $offerings->map(function (CourseOffering $o) use ($plan, $profile, $counts, $mine, $passed, $failed, $prereqs) {
            $entry = $plan[$o->course_id] ?? null;
            $category = in_array($o->course_id, $failed, true) ? 'carryover' : ($entry->category ?? 'elective');
            $blocked = null;
            if ($plan->isNotEmpty() && ! $entry && $category !== 'carryover') {
                $blocked = 'Not in your programme';
            } elseif ($entry && $profile?->level && $entry->level > $profile->level) {
                $blocked = "For {$entry->level} level";
            } elseif ($missing = collect($prereqs[$o->course_id] ?? [])->pluck('prerequisite_id')->diff($passed)->all()) {
                $blocked = 'Needs '.DB::table('courses')->whereIn('id', $missing)->pluck('code')->implode(', ');
            } elseif ($o->capacity && ($counts[$o->id] ?? 0) >= $o->capacity && ! isset($mine[$o->id])) {
                $blocked = 'Full';
            } elseif (in_array($o->course_id, $passed, true)) {
                $blocked = 'Already passed';
            }

            return [
                'id' => $o->id, 'code' => $o->course->code, 'title' => $o->course->title, 'units' => $o->course->credit_units,
                'level' => $entry->level ?? $o->course->level, 'category' => $category, 'status' => $mine[$o->id] ?? null,
                'seats_left' => $o->capacity ? max(0, $o->capacity - ($counts[$o->id] ?? 0)) : null, 'blocked' => $blocked,
            ];
        })->sortBy([['category', 'asc'], ['code', 'asc']])->values();
    }

    /**
     * Replace the student's registration for the semester with $offeringIds, all or nothing.
     * $override lets registry/HOD register outside the window or limits (recorded in the audit log).
     */
    public function register(User $student, Semester $semester, array $offeringIds, ?User $override = null, ?string $reason = null): array
    {
        $profile = $student->studentProfile;
        $window = $this->window($semester);
        $state = $this->windowState($window);
        $errors = [];

        if (! $override) {
            if (! in_array($state, ['open', 'late'], true)) {
                $errors[] = $state === 'none' ? 'Registration has not been set up for this semester.' : 'Registration is closed.';
            }
            if ($profile && $profile->academic_status !== 'active') {
                $errors[] = "Your status is \"{$profile->academic_status}\"; please see Registry.";
            }
        }

        $catalogue = $this->catalogue($student, $semester)->keyBy('id');
        $chosen = collect(array_unique(array_map('intval', $offeringIds)));
        foreach ($chosen as $id) {
            $c = $catalogue[$id] ?? null;
            if (! $c) {
                $errors[] = 'One of the courses is not offered this semester.';
            } elseif ($c['blocked'] && ! $override) {
                $errors[] = "{$c['code']}: {$c['blocked']}.";
            }
        }
        $units = $chosen->sum(fn ($id) => $catalogue[$id]['units'] ?? 0);
        if (! $override && $window) {
            if ($units > $window->max_units) {
                $errors[] = "You chose {$units} units; the maximum is {$window->max_units}.";
            }
            if ($units < $window->min_units) {
                $errors[] = "You chose {$units} units; the minimum is {$window->min_units}.";
            }
            $missingCore = $catalogue->where('category', 'core')->whereNull('blocked')->pluck('id')->diff($chosen);
            if ($missingCore->isNotEmpty()) {
                $errors[] = 'Compulsory courses missing: '.$catalogue->only($missingCore->all())->pluck('code')->implode(', ').'.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['courses' => $errors]);
        }

        $status = $window?->requires_approval && ! $override ? 'pending' : 'active';

        return DB::transaction(function () use ($student, $semester, $chosen, $catalogue, $status, $override, $reason, $state, $units) {
            // Capacity re-checked under lock so two students cannot take the last seat
            $offerings = CourseOffering::whereIn('id', $chosen)->lockForUpdate()->get()->keyBy('id');
            foreach ($offerings as $o) {
                if ($o->capacity && ! $override) {
                    $taken = Enrolment::where('course_offering_id', $o->id)->whereIn('status', ['active', 'pending'])->where('user_id', '!=', $student->id)->count();
                    if ($taken >= $o->capacity) {
                        throw ValidationException::withMessages(['courses' => ["{$catalogue[$o->id]['code']} has just become full."]]);
                    }
                }
            }

            $semesterOfferings = CourseOffering::where('semester_id', $semester->id)->pluck('id');
            $dropped = Enrolment::where('user_id', $student->id)->whereIn('course_offering_id', $semesterOfferings)
                ->whereNotIn('course_offering_id', $chosen)->whereIn('status', ['active', 'pending'])->get();
            foreach ($dropped as $e) {
                $e->update(['status' => 'dropped']);
            }
            foreach ($chosen as $id) {
                $e = Enrolment::firstOrNew(['course_offering_id' => $id, 'user_id' => $student->id]);
                $keep = $e->exists && in_array($e->status, ['active', 'pending'], true);
                $e->fill([
                    'category' => $catalogue[$id]['category'], 'source' => $override ? 'override' : 'self',
                    'status' => $keep ? $e->status : $status,
                    'approved_at' => $status === 'active' && ! $keep ? now() : $e->approved_at,
                    'approved_by' => $override?->id ?? $e->approved_by, 'note' => $reason ?? ($state === 'late' ? 'Late registration' : $e->note),
                ])->save();
            }
            $this->audit->record($override ? 'registration.override' : 'registration.saved', $student, [
                'semester' => $semester->id, 'courses' => $chosen->values(), 'units' => $units, 'dropped' => $dropped->pluck('course_offering_id'),
                'by' => $override?->id, 'reason' => $reason,
            ]);

            return ['units' => $units, 'status' => $status, 'dropped' => $dropped->count()];
        });
    }

    public function approve(Enrolment $enrolment, User $by, bool $approve, ?string $note = null): void
    {
        $enrolment->update(['status' => $approve ? 'active' : 'rejected', 'approved_at' => now(), 'approved_by' => $by->id, 'note' => $note]);
        $this->audit->record($approve ? 'registration.approved' : 'registration.rejected', $enrolment, ['note' => $note]);
    }

    /** Courses passed (released course grade other than F), used for prerequisites. */
    public function passedCourseIds(User $student): array
    {
        return DB::table('course_grades')->join('course_offerings', 'course_offerings.id', '=', 'course_grades.course_offering_id')
            ->where('course_grades.user_id', $student->id)->where('course_grades.status', 'released')->where('course_grades.grade', '!=', 'F')
            ->pluck('course_offerings.course_id')->unique()->values()->all();
    }

    public function failedCourseIds(User $student): array
    {
        $passed = $this->passedCourseIds($student);

        return DB::table('course_grades')->join('course_offerings', 'course_offerings.id', '=', 'course_grades.course_offering_id')
            ->where('course_grades.user_id', $student->id)->where('course_grades.status', 'released')->where('course_grades.grade', 'F')
            ->pluck('course_offerings.course_id')->unique()->diff($passed)->values()->all();
    }
}
