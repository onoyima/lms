<?php

namespace App\Services\Integrations;

use App\Models\AttendanceRecord;
use App\Models\ClassMeeting;
use App\Models\CourseOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Attendance from outside systems — card readers, biometric terminals, a timetable app (LMS 221).
 * Each record names the course, the student and the time; it lands on the class meeting at that
 * time (made if missing), so attendance rules for exam eligibility apply to it as usual.
 */
class AttendanceImport
{
    public function import(array $records): array
    {
        $done = ['recorded' => 0, 'meetings_created' => 0, 'errors' => []];
        $offerings = [];
        DB::transaction(function () use ($records, &$done, &$offerings) {
            foreach (array_values($records) as $n => $r) {
                $at = CarbonImmutable::parse($r['held_at']);
                $code = strtoupper(trim($r['course_code']));
                $offering = $offerings[$code.$at->toDateString()] ??= CourseOffering::whereHas('course', fn ($q) => $q->where('code', $code))
                    ->whereHas('semester', fn ($q) => $q->where(fn ($w) => $w->whereNull('starts_on')->orWhere('starts_on', '<=', $at->toDateString()))
                        ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $at->toDateString())))
                    ->latest('id')->first();
                if (! $offering) {
                    $done['errors'][] = ['row' => $n, 'error' => "No course $code running on ".$at->toDateString()];

                    continue;
                }
                $user = User::where('identifier', trim($r['identifier']))->first();
                if (! $user || ! DB::table('enrolments')->where('course_offering_id', $offering->id)->where('user_id', $user->id)->where('status', 'active')->exists()) {
                    $done['errors'][] = ['row' => $n, 'error' => 'Student not registered for '.$code];

                    continue;
                }
                $meeting = ClassMeeting::where('course_offering_id', $offering->id)->whereBetween('held_at', [$at->subHours(2), $at->addHours(2)])->get()
                    ->sortBy(fn ($m) => abs($m->held_at->diffInSeconds($at, false)))->first(); // the class nearest in time
                if (! $meeting) {
                    $meeting = ClassMeeting::create(['course_offering_id' => $offering->id, 'held_at' => $at, 'topic' => 'Recorded by '.($r['device'] ?? 'attendance system')]);
                    $done['meetings_created']++;
                }
                AttendanceRecord::updateOrCreate(['class_meeting_id' => $meeting->id, 'user_id' => $user->id], ['status' => $r['status'] ?? 'present', 'source' => 'import']);
                $done['recorded']++;
            }
        });

        return $done;
    }
}
