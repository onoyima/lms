<?php

namespace App\Services\Lms;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One calendar per person (LMS 75-78, 199): weekly classes, one-off class meetings, assignment
 * and quiz deadlines, exam sittings, proctoring shifts and registration windows.
 */
class CalendarService
{
    /** @return list<array{kind: string, title: string, starts_at: string, ends_at: ?string, where: ?string, url: ?string, course: ?string}> */
    public function events(User $u, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $learning = DB::table('enrolments')->where('user_id', $u->id)->where('status', 'active')->pluck('course_offering_id');
        $teaching = DB::table('course_offering_staff')->where('user_id', $u->id)->pluck('course_offering_id');
        $offerings = $learning->merge($teaching)->unique();
        $codes = DB::table('course_offerings as o')->join('courses as c', 'c.id', '=', 'o.course_id')->whereIn('o.id', $offerings)->pluck('c.code', 'o.id');
        $out = [];

        // weekly classes, expanded day by day
        $schedules = DB::table('class_schedules')->whereIn('course_offering_id', $offerings)->get();
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            foreach ($schedules->where('weekday', $d->dayOfWeekIso) as $s) {
                if (($s->valid_from && $d->lt(CarbonImmutable::parse($s->valid_from))) || ($s->valid_until && $d->gt(CarbonImmutable::parse($s->valid_until)))) {
                    continue;
                }
                $out[] = ['kind' => 'class', 'title' => ($codes[$s->course_offering_id] ?? '').' '.$s->kind.($s->group_label ? ' · '.$s->group_label : ''),
                    'starts_at' => $d->setTimeFromTimeString($s->starts_at)->toIso8601String(), 'ends_at' => $d->setTimeFromTimeString($s->ends_at)->toIso8601String(),
                    'where' => $s->venue, 'url' => '/learn/courses/'.$s->course_offering_id, 'course' => $codes[$s->course_offering_id] ?? null];
            }
        }
        foreach (DB::table('class_meetings')->whereIn('course_offering_id', $offerings)->whereBetween('held_at', [$from, $to])->get() as $m) {
            $out[] = ['kind' => 'meeting', 'title' => ($codes[$m->course_offering_id] ?? '').' '.($m->topic ?: 'class'), 'starts_at' => CarbonImmutable::parse($m->held_at)->toIso8601String(),
                'ends_at' => null, 'where' => null, 'url' => '/learn/courses/'.$m->course_offering_id, 'course' => $codes[$m->course_offering_id] ?? null];
        }
        // deadlines (for students, extensions move them)
        $extensions = DB::table('deadline_extensions')->where('user_id', $u->id)->pluck('due_at', 'course_item_id');
        foreach (DB::table('course_items')->whereIn('course_offering_id', $offerings)->whereIn('type', ['assignment', 'quiz'])->where('visible', true)->whereNull('deleted_at')
            ->whereNotNull('due_at')->whereBetween('due_at', [$from->subDays(30), $to])->get(['id', 'title', 'type', 'due_at', 'course_offering_id']) as $i) {
            $due = CarbonImmutable::parse($extensions[$i->id] ?? $i->due_at);
            if ($due->between($from, $to)) {
                $out[] = ['kind' => 'due', 'title' => ucfirst($i->type).' due: '.$i->title, 'starts_at' => $due->toIso8601String(), 'ends_at' => null, 'where' => null,
                    'url' => '/learn/items/'.$i->id, 'course' => $codes[$i->course_offering_id] ?? null];
            }
        }
        // exams the person sits, and shifts they work
        foreach (DB::table('exam_candidates as c')->join('exam_sessions as s', 's.id', '=', 'c.exam_session_id')->join('exams as e', 'e.id', '=', 's.exam_id')->leftJoin('venues as v', 'v.id', '=', 's.venue_id')
            ->where('c.user_id', $u->id)->where('s.is_practice', false)->whereBetween('s.starts_at', [$from, $to])->get(['c.id', 'e.title', 's.starts_at', 's.ends_at', 'v.name as venue', 's.mode']) as $x) {
            $out[] = ['kind' => 'exam', 'title' => $x->title, 'starts_at' => CarbonImmutable::parse($x->starts_at)->toIso8601String(), 'ends_at' => CarbonImmutable::parse($x->ends_at)->toIso8601String(),
                'where' => $x->venue ?? ($x->mode === 'remote' ? 'Online' : null), 'url' => '/my/exams/'.$x->id, 'course' => null];
        }
        foreach (DB::table('proctor_assignments as p')->join('exam_sessions as s', 's.id', '=', 'p.exam_session_id')->join('exams as e', 'e.id', '=', 's.exam_id')->leftJoin('venues as v', 'v.id', '=', 's.venue_id')
            ->where('p.user_id', $u->id)->whereBetween('s.starts_at', [$from, $to])->get(['s.id', 'e.title', 's.starts_at', 's.ends_at', 'v.name as venue', 'p.role']) as $x) {
            $out[] = ['kind' => 'shift', 'title' => ucfirst($x->role).': '.$x->title, 'starts_at' => CarbonImmutable::parse($x->starts_at)->subMinutes(30)->toIso8601String(),
                'ends_at' => CarbonImmutable::parse($x->ends_at)->toIso8601String(), 'where' => $x->venue, 'url' => '/proctor/sessions/'.$x->id, 'course' => null];
        }
        if ($u->user_type === 'student') {
            foreach (DB::table('registration_windows')->where('tenant_id', tenant_id())->where(fn ($q) => $q->whereBetween('opens_at', [$from, $to])->orWhereBetween('closes_at', [$from, $to]))->get() as $w) {
                foreach (['opens_at' => 'Course registration opens', 'closes_at' => 'Course registration closes'] as $k => $label) {
                    $t = CarbonImmutable::parse($w->$k);
                    $t->between($from, $to) && $out[] = ['kind' => 'registration', 'title' => $label, 'starts_at' => $t->toIso8601String(), 'ends_at' => null, 'where' => null, 'url' => '/learn/registration', 'course' => null];
                }
            }
        }
        usort($out, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

        return $out;
    }

    /** iCalendar (RFC 5545) text for calendar apps. */
    public function ics(User $u, array $events): string
    {
        $esc = fn (?string $s) => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], (string) $s);
        $fmt = fn (string $iso) => CarbonImmutable::parse($iso)->utc()->format('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//'.str_replace('/', ' ', \App\Support\Platform\PlatformSettings::brand()['name']).'//Calendar//EN', 'CALSCALE:GREGORIAN', 'X-WR-CALNAME:'.$esc(tenant()?->name.' — '.$u->name)];
        foreach ($events as $i => $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.sha1($u->id.$e['kind'].$e['title'].$e['starts_at']).'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'calendar');
            $lines[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:'.$fmt($e['starts_at']);
            $lines[] = 'DTEND:'.$fmt($e['ends_at'] ?? CarbonImmutable::parse($e['starts_at'])->addMinutes(30)->toIso8601String());
            $lines[] = 'SUMMARY:'.$esc($e['title']);
            $e['where'] && $lines[] = 'LOCATION:'.$esc($e['where']);
            $e['url'] && $lines[] = 'URL:'.url($e['url']);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }
}
