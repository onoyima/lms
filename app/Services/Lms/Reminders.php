<?php

namespace App\Services\Lms;

use App\Models\User;
use App\Notifications\LmsNotice;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled reminders (LMS 99-101, 199; Target 46): work due in 24 hours and in 2 hours (only to
 * those who have not handed in), exams tomorrow, proctoring shifts tomorrow, and registration
 * opening or about to close. Each reminder is sent once per person.
 */
class Reminders
{
    public function run(): int
    {
        $sent = 0;
        $now = now();
        $tid = tenant_id();

        foreach ([['due24', 24, 23], ['due2', 2, 0]] as [$key, $hi, $lo]) {
            $items = DB::table('course_items as i')->join('course_offerings as o', 'o.id', '=', 'i.course_offering_id')->join('courses as c', 'c.id', '=', 'o.course_id')
                ->where('i.tenant_id', $tid)->whereIn('i.type', ['assignment', 'quiz'])->where('i.visible', true)->whereNull('i.deleted_at')
                ->whereBetween('i.due_at', [$now->copy()->addHours($lo), $now->copy()->addHours($hi)])->get(['i.id', 'i.title', 'i.due_at', 'i.course_offering_id', 'c.code']);
            foreach ($items as $i) {
                $handedIn = DB::table('assignment_submissions')->where('course_item_id', $i->id)->whereIn('status', ['submitted', 'marked', 'returned'])->pluck('user_id')
                    ->merge(DB::table('item_completions')->where('course_item_id', $i->id)->pluck('user_id'));
                $extended = DB::table('deadline_extensions')->where('course_item_id', $i->id)->pluck('user_id');
                $students = DB::table('enrolments')->where('course_offering_id', $i->course_offering_id)->where('status', 'active')->pluck('user_id')->diff($handedIn)->diff($extended);
                $sent += $this->send($students, "$key:item:{$i->id}", "{$i->code}: {$i->title} is due ".($key === 'due2' ? 'in 2 hours' : 'tomorrow'),
                    'Due '.\Carbon\Carbon::parse($i->due_at)->format('D j M, H:i').'.', '/learn/items/'.$i->id);
            }
        }

        $sessions = DB::table('exam_sessions as s')->join('exams as e', 'e.id', '=', 's.exam_id')->leftJoin('venues as v', 'v.id', '=', 's.venue_id')
            ->where('s.tenant_id', $tid)->where('s.is_practice', false)->whereBetween('s.starts_at', [$now->copy()->addHours(23), $now->copy()->addHours(25)])
            ->get(['s.id', 'e.title', 's.starts_at', 's.mode', 'v.name as venue']);
        foreach ($sessions as $s) {
            $when = \Carbon\Carbon::parse($s->starts_at)->format('D j M, H:i').' · '.($s->venue ?? ($s->mode === 'remote' ? 'online' : 'venue on your slip'));
            $candidates = DB::table('exam_candidates')->where('exam_session_id', $s->id)->whereIn('eligibility', ['eligible', 'override'])->pluck('user_id');
            $sent += $this->send($candidates, "exam24:{$s->id}", "Exam tomorrow: {$s->title}", $when.'. Bring your ID card and exam slip; run the system check if you sit online.', '/my/exams');
            $sent += $this->send(DB::table('proctor_assignments')->where('exam_session_id', $s->id)->pluck('user_id'), "shift24:{$s->id}", "Proctoring tomorrow: {$s->title}", $when.'. Arrive 30 minutes early.', '/proctor/shifts');
        }

        foreach (DB::table('registration_windows')->where('tenant_id', $tid)->get() as $w) {
            $students = fn () => DB::table('users')->where('tenant_id', $tid)->where('user_type', 'student')->where('status', 'active')->pluck('id');
            if ($w->opens_at && \Carbon\Carbon::parse($w->opens_at)->between($now->copy()->subHours(2), $now->copy()->addHour())) {
                $sent += $this->send($students(), "regopen:{$w->id}", 'Course registration is open', 'Register for your courses before '.\Carbon\Carbon::parse($w->closes_at)->format('D j M, H:i').'.', '/learn/registration');
            }
            if ($w->closes_at && \Carbon\Carbon::parse($w->closes_at)->between($now, $now->copy()->addDay())) {
                $registered = DB::table('enrolments as e')->join('course_offerings as o', 'o.id', '=', 'e.course_offering_id')->where('o.semester_id', $w->semester_id)->pluck('e.user_id');
                $sent += $this->send($students()->diff($registered), "regclose:{$w->id}", 'Course registration closes soon', 'You have not registered any courses yet. Registration closes '.\Carbon\Carbon::parse($w->closes_at)->format('D j M, H:i').'.', '/learn/registration');
            }
        }

        return $sent;
    }

    private function send($userIds, string $key, string $title, string $body, string $url): int
    {
        $ids = collect($userIds)->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }
        $already = DB::table('reminder_log')->whereIn('user_id', $ids)->where('key', $key)->pluck('user_id')->flip();
        $fresh = $ids->reject(fn ($id) => isset($already[$id]));
        foreach ($fresh->chunk(500) as $chunk) {
            DB::table('reminder_log')->insertOrIgnore($chunk->map(fn ($id) => ['user_id' => $id, 'key' => $key, 'created_at' => now()])->all());
            \Illuminate\Support\Facades\Notification::send(User::whereIn('id', $chunk)->get(), new LmsNotice($title, $body, $url));
        }

        return $fresh->count();
    }
}
