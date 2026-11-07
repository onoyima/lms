<?php

namespace App\Services\Academics;

use App\Models\AcademicSession;
use App\Models\CourseOffering;
use App\Models\Semester;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Lms\CourseContentService;
use App\Support\Policy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Automatic semester rollover and course archival (LMS 172), when the institution switches it on
 * in Policy. Every night: a semester whose end date has passed is closed and its courses archived
 * (only once all its results are released); the semester whose dates have started becomes current,
 * and the courses of the same semester last session are carried over, with their content if asked.
 */
class SemesterRollover
{
    public function __construct(private AuditLogger $audit, private CourseContentService $content) {}

    public function auto(): array
    {
        $done = ['closed' => null, 'activated' => null, 'carried' => 0, 'blocked' => null];
        if (! Policy::get('auto_rollover')) {
            return $done + ['skipped' => 'off'];
        }
        $today = now()->toDateString();
        $current = Semester::where('is_current', true)->first();
        if ($current && $current->ends_on && $current->ends_on->toDateString() < $today) {
            $open = $this->unreleased($current);
            if ($open > 0) {
                $done['blocked'] = "$open course results in {$current->name} are not released";
                if (Cache::add('rollover-blocked:'.tenant_id().':'.$current->id, 1, now()->addDays(3))) {
                    $this->tell('Semester could not close', "{$current->name} ended on {$current->ends_on->format('j M Y')}, but $open course results are not released. It will close by itself once they are.");
                }

                return $done;
            }
            $this->close($current);
            $done['closed'] = $current->name;
            $current = null;
        }
        if (! $current) {
            $next = Semester::where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'closed'))->where('starts_on', '<=', $today)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->orderByDesc('starts_on')->first();
            if ($next) {
                $this->activate($next);
                $done['activated'] = $next->name;
                $from = Semester::where('id', '!=', $next->id)->where('name', $next->name)->where('ends_on', '<', $next->starts_on)->orderByDesc('ends_on')->first();
                if ($from && ! CourseOffering::where('semester_id', $next->id)->exists()) {
                    $done['carried'] = $this->carryOver($from, $next, (bool) Policy::get('auto_rollover_copy_content'));
                }
                $this->tell('New semester started', "{$next->name} is now the current semester".($done['carried'] ? "; {$done['carried']} courses were carried over from {$from->name}" : '').'. Check lecturers and registration dates.');
            }
        }

        return $done;
    }

    public function unreleased(Semester $s): int
    {
        return DB::table('course_grades')->join('course_offerings', 'course_offerings.id', '=', 'course_grades.course_offering_id')
            ->where('course_offerings.semester_id', $s->id)->where('course_grades.status', '!=', 'released')->count();
    }

    public function close(Semester $s): void
    {
        DB::transaction(function () use ($s) {
            CourseOffering::where('semester_id', $s->id)->update(['status' => 'archived']);
            DB::table('registration_windows')->where('semester_id', $s->id)->update(['closes_at' => now(), 'late_closes_at' => null]);
            $s->forceFill(['status' => 'closed', 'closed_at' => now(), 'is_current' => false])->save();
        });
        $this->audit->record('academics.semester.auto_closed', $s);
    }

    public function activate(Semester $s): void
    {
        DB::transaction(function () use ($s) {
            Semester::where('id', '!=', $s->id)->where('is_current', true)->update(['is_current' => false]);
            AcademicSession::where('id', '!=', $s->academic_session_id)->update(['is_current' => false]);
            $s->forceFill(['is_current' => true, 'status' => 'active'])->save();
            AcademicSession::whereKey($s->academic_session_id)->update(['is_current' => true, 'status' => 'active']);
        });
        $this->audit->record('academics.semester.auto_activated', $s);
    }

    public function carryOver(Semester $from, Semester $to, bool $copyContent, ?User $by = null): int
    {
        $n = 0;
        // automatic runs copy content in the name of the institution's first administrator
        $by ??= User::permission('institution.manage')->where('status', 'active')->orderBy('id')->first();
        $copyContent = $copyContent && $by !== null;
        foreach (CourseOffering::with('staff')->where('semester_id', $from->id)->get() as $old) {
            $new = CourseOffering::firstOrCreate(['course_id' => $old->course_id, 'semester_id' => $to->id], ['status' => 'open', 'capacity' => $old->capacity]);
            $new->staff()->syncWithoutDetaching($old->staff->mapWithKeys(fn ($s) => [$s->id => ['role' => $s->pivot->role]])->all());
            if ($copyContent && ! $new->sections()->exists()) {
                $this->content->copyFrom($old, $new, $by);
            }
            $n++;
        }
        $this->audit->record('academics.semester.carried_over', $to, ['from' => $from->id, 'courses' => $n, 'content' => $copyContent]);

        return $n;
    }

    private function tell(string $title, string $body): void
    {
        Notification::send(User::permission('academics.manage')->where('status', 'active')->get(), new LmsNotice($title, $body, '/admin/academics?tab=calendar', category: 'system'));
    }
}
