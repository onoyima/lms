<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use App\Models\CourseOffering;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Lms\CalendarService;
use App\Services\Lms\LmsAccess;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Personal calendar, private calendar feed, course timetable, and search across courses. */
class CalendarController extends Controller
{
    public function __construct(private CalendarService $calendar, private LmsAccess $access, private AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        $start = CarbonImmutable::parse($request->query('week', now()->toDateString()))->startOfWeek();
        $u = $request->user();

        return Inertia::render('learn/calendar', [
            'week' => $start->toDateString(),
            'events' => $this->calendar->events($u, $start, $start->addDays(7)->subSecond()),
            'feed' => $u->calendar_token ? url('/calendar/'.$u->calendar_token.'.ics') : null,
        ]);
    }

    /** A private link for phone and desktop calendar apps; making a new one cancels the old. */
    public function token(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['calendar_token' => $request->boolean('remove') ? null : Str::random(48)])->save();

        return back()->with('success', $request->boolean('remove') ? 'Calendar link turned off.' : 'New calendar link made. Old links stop working.');
    }

    public function feed(string $token)
    {
        $u = User::withoutGlobalScopes()->where('calendar_token', $token)->where('status', 'active')->first();
        abort_unless($u && $u->tenant_id, 404);

        return app(TenantContext::class)->run(Tenant::find($u->tenant_id), function () use ($u) {
            $events = $this->calendar->events($u, CarbonImmutable::now()->subDays(14), CarbonImmutable::now()->addDays(120));

            return response($this->calendar->ics($u, $events), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'private, max-age=900']);
        });
    }

    /** Weekly class times for a course; students are told when they change. */
    public function saveSchedule(Request $request, CourseOffering $offering): RedirectResponse
    {
        $this->access->authorizeTeach($request->user(), $offering);
        $data = $request->validate([
            'slots' => ['present', 'array', 'max:30'], 'slots.*.weekday' => ['required', 'integer', 'between:1,7'],
            'slots.*.starts_at' => ['required', 'date_format:H:i'], 'slots.*.ends_at' => ['required', 'date_format:H:i', 'after:slots.*.starts_at'],
            'slots.*.kind' => ['required', 'in:lecture,practical,tutorial,seminar'], 'slots.*.venue' => ['nullable', 'string', 'max:120'], 'slots.*.group_label' => ['nullable', 'string', 'max:40'],
            'notify' => ['boolean'],
        ]);
        $before = ClassSchedule::where('course_offering_id', $offering->id)->orderBy('weekday')->orderBy('starts_at')->get(['weekday', 'starts_at', 'ends_at', 'kind', 'venue', 'group_label'])
            ->map(fn ($s) => [$s->weekday, substr($s->starts_at, 0, 5), substr($s->ends_at, 0, 5), $s->kind, $s->venue, $s->group_label])->all();
        DB::transaction(function () use ($offering, $data) {
            ClassSchedule::where('course_offering_id', $offering->id)->delete();
            foreach ($data['slots'] as $s) {
                ClassSchedule::create(['course_offering_id' => $offering->id] + array_intersect_key($s, array_flip(['weekday', 'starts_at', 'ends_at', 'kind', 'venue', 'group_label'])));
            }
        });
        $after = collect($data['slots'])->sortBy(fn ($s) => sprintf('%d%s', $s['weekday'], $s['starts_at']))
            ->map(fn ($s) => [(int) $s['weekday'], $s['starts_at'], $s['ends_at'], $s['kind'], $s['venue'] ?? null, $s['group_label'] ?? null])->values()->all();
        $changed = $before !== $after;
        if ($changed && ($data['notify'] ?? true)) {
            $students = User::whereIn('id', DB::table('enrolments')->where('course_offering_id', $offering->id)->where('status', 'active')->pluck('user_id'))->get();
            $code = $offering->course?->code;
            Notification::send($students, new LmsNotice("Timetable changed: $code", 'The class times or venues for '.$code.' have changed. Check your calendar.', '/learn/calendar'));
        }
        $this->audit->record('timetable.saved', $offering, ['slots' => count($data['slots']), 'changed' => $changed]);

        return back()->with('success', $changed ? 'Timetable saved and students told.' : 'Timetable saved.');
    }

    /** Search everything the person can open: course pages, materials, announcements, help. */
    public function search(Request $request): Response
    {
        $q = trim((string) $request->query('q'));
        $u = $request->user();
        $results = [];
        if (mb_strlen($q) >= 2) {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $offerings = DB::table('enrolments')->where('user_id', $u->id)->where('status', 'active')->pluck('course_offering_id')
                ->merge(DB::table('course_offering_staff')->where('user_id', $u->id)->pluck('course_offering_id'))->unique();
            $teaching = DB::table('course_offering_staff')->where('user_id', $u->id)->pluck('course_offering_id')->flip();
            foreach (DB::table('course_items as i')->join('course_offerings as o', 'o.id', '=', 'i.course_offering_id')->join('courses as c', 'c.id', '=', 'o.course_id')
                ->whereIn('i.course_offering_id', $offerings)->whereNull('i.deleted_at')
                ->where(fn ($w) => $w->where('i.title', 'like', $like)->orWhere('i.body_html', 'like', $like))
                ->limit(60)->get(['i.id', 'i.title', 'i.type', 'i.visible', 'i.course_offering_id', 'c.code', 'i.body_html']) as $i) {
                if (! $i->visible && ! isset($teaching[$i->course_offering_id])) {
                    continue; // hidden items only for teachers
                }
                $results[] = ['kind' => $i->type, 'title' => $i->title, 'course' => $i->code, 'url' => '/learn/items/'.$i->id, 'snippet' => $this->snippet($i->body_html, $q)];
            }
            foreach (DB::table('courses as c')->join('course_offerings as o', 'o.course_id', '=', 'c.id')->whereIn('o.id', $offerings)
                ->where(fn ($w) => $w->where('c.code', 'like', $like)->orWhere('c.title', 'like', $like))->limit(20)->get(['o.id', 'c.code', 'c.title']) as $c) {
                $results[] = ['kind' => 'course', 'title' => $c->code.' '.$c->title, 'course' => $c->code, 'url' => '/learn/courses/'.$c->id, 'snippet' => null];
            }
            foreach (DB::table('help_articles')->where('tenant_id', tenant_id())->where('published', true)
                ->whereIn('audience', $u->user_type === 'student' ? ['all', 'students'] : ['all', 'students', 'staff'])
                ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('body_html', 'like', $like))->limit(20)->get(['slug', 'title', 'body_html']) as $a) {
                $results[] = ['kind' => 'help', 'title' => $a->title, 'course' => null, 'url' => '/help/'.$a->slug, 'snippet' => $this->snippet($a->body_html, $q)];
            }
        }

        return Inertia::render('learn/search', ['q' => $q, 'results' => $results]);
    }

    private function snippet(?string $html, string $q): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html))));
        $pos = mb_stripos($text, $q);

        return $text === '' ? null : ($pos === false ? mb_substr($text, 0, 160) : '…'.mb_substr($text, max(0, $pos - 60), 160).'…');
    }
}
