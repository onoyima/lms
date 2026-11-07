<?php

namespace App\Http\Controllers\Conferencing;

use App\Conferencing\Attendance;
use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Meeting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Reports\Exporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Live-class reports: for one course, how many of its classes each student attended and for how long,
 * and for each class how many came, on time, and for what share of it. Downloadable as Excel, PDF or CSV.
 */
class MeetingReportController extends Controller
{
    public function __construct(private Attendance $attendance, private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $u = $request->user();
        abort_unless(app(\App\Conferencing\MeetingAccess::class)->canCreate($u), 403);
        $courses = $this->courses($u);
        $course = $courses->firstWhere('id', $request->integer('course')) ?? null;

        return Inertia::render('meetings/reports', [
            'courses' => $courses->map(fn ($o) => ['id' => $o->id, 'label' => $o->label(), 'title' => $o->course->title])->values(),
            'course' => $course ? ['id' => $course->id, 'label' => $course->label()] : null,
            'filters' => ['from' => $request->query('from'), 'to' => $request->query('to')],
            'report' => $course ? $this->build($course, $request->query('from'), $request->query('to')) : null,
            'overview' => $this->overview($u, $courses->pluck('id')->all()),
        ]);
    }

    public function export(Request $request)
    {
        $u = $request->user();
        abort_unless(app(\App\Conferencing\MeetingAccess::class)->canCreate($u), 403);
        $course = $this->courses($u)->firstWhere('id', $request->integer('course'));
        abort_unless($course, 404);
        $r = $this->build($course, $request->query('from'), $request->query('to'));
        $format = in_array($request->query('format'), ['xlsx', 'csv', 'pdf'], true) ? $request->query('format') : 'xlsx';
        $this->audit->record('meeting.report_exported', $course, ['format' => $format, 'meetings' => count($r['meetings'])]);

        $columns = array_merge(['Name', 'ID number'], array_map(fn ($m) => $m['date'].' '.$m['title'], $r['meetings']), ['Classes attended', 'Of', 'Average % present', 'Minutes in total']);
        $rows = array_map(fn ($s) => array_merge([$s['name'], $s['identifier']], array_map(fn ($c) => $c === null ? 'absent' : $c['percent'].'% ('.$c['status'].')', $s['cells']),
            [$s['attended'], count($r['meetings']), $s['average'], $s['minutes']]), $r['students']);
        $name = 'live-class-attendance-'.\Illuminate\Support\Str::slug($course->course->code);
        $exporter = app(Exporter::class);

        return match ($format) {
            'csv' => $exporter->csv($name, $columns, $rows),
            'pdf' => $exporter->pdf($name, 'pdf.report', ['title' => 'Live-class attendance — '.$course->label(), 'columns' => $columns, 'rows' => $rows, 'truncated' => false,
                'subtitle' => count($r['meetings']).' classes · '.count($r['students']).' students · average attendance '.$r['summary']['average_turnout'].'%', 'footer' => $u->name], 'landscape'),
            default => $exporter->xlsx($name, 'Attendance', $columns, $rows),
        };
    }

    /** Courses whose classes this person may report on: all for conferencing managers, otherwise the ones they teach. */
    private function courses(User $u)
    {
        $q = CourseOffering::with('course', 'semester.session')->orderByDesc('id');
        if (! $u->can('conferencing.manage')) {
            $q->whereIn('id', DB::table('course_offering_staff')->where('user_id', $u->id)->select('course_offering_id'));
        }

        return $q->limit(300)->get();
    }

    private function build(CourseOffering $course, ?string $from, ?string $to): array
    {
        $meetings = Meeting::where('course_offering_id', $course->id)->where('status', 'ended')
            ->when($from, fn ($q) => $q->where('starts_at', '>=', $from))->when($to, fn ($q) => $q->where('starts_at', '<=', $to.' 23:59:59'))
            ->orderBy('starts_at')->limit(80)->get();
        $students = DB::table('enrolments')->join('users', 'users.id', '=', 'enrolments.user_id')->where('enrolments.course_offering_id', $course->id)
            ->where('enrolments.status', 'active')->orderBy('users.name')->get(['users.id', 'users.name', 'users.identifier']);

        $byMeeting = [];
        $perMeeting = [];
        foreach ($meetings as $m) {
            $sum = collect($this->attendance->summary($m))->filter(fn ($p) => $p['user_id'])->keyBy('user_id');
            $byMeeting[$m->id] = $sum;
            $came = $students->filter(fn ($s) => $sum->has($s->id));
            $perMeeting[] = ['id' => $m->uuid, 'title' => $m->title, 'date' => $m->starts_at->format('Y-m-d'), 'minutes' => $m->duration_minutes,
                'attended' => $came->count(), 'late' => $came->filter(fn ($s) => $sum[$s->id]['status'] === 'late')->count(),
                'partial' => $came->filter(fn ($s) => $sum[$s->id]['status'] === 'partial')->count(),
                'turnout' => $students->count() ? (int) round(100 * $came->count() / $students->count()) : 0,
                'average_minutes' => $came->count() ? round($came->avg(fn ($s) => $sum[$s->id]['seconds']) / 60, 1) : 0];
        }

        $rows = $students->map(function ($s) use ($meetings, $byMeeting) {
            $cells = $meetings->map(fn ($m) => ($p = $byMeeting[$m->id][$s->id] ?? null) ? ['percent' => $p['percent'], 'status' => $p['status'], 'minutes' => round($p['seconds'] / 60)] : null)->values()->all();
            $present = array_filter($cells);

            return ['id' => $s->id, 'name' => $s->name, 'identifier' => $s->identifier, 'cells' => $cells, 'attended' => count($present),
                'average' => $cells ? (int) round(array_sum(array_map(fn ($c) => $c['percent'] ?? 0, $cells)) / count($cells)) : 0,
                'minutes' => (int) array_sum(array_map(fn ($c) => $c['minutes'], $present))];
        })->values()->all();

        return [
            'meetings' => $perMeeting,
            'students' => $rows,
            'summary' => [
                'classes' => count($perMeeting), 'students' => count($rows),
                'average_turnout' => $perMeeting ? (int) round(collect($perMeeting)->avg('turnout')) : 0,
                'never' => count(array_filter($rows, fn ($r) => $r['attended'] === 0)),
                'below_half' => count(array_filter($rows, fn ($r) => $r['average'] < 50)),
            ],
        ];
    }

    /** Numbers across the courses this person can see, for the last 30 days. */
    private function overview(User $u, array $courseIds): array
    {
        $q = Meeting::where('starts_at', '>=', now()->subDays(30))
            ->when(! $u->can('conferencing.manage'), fn ($w) => $w->where(fn ($x) => $x->whereIn('course_offering_id', $courseIds)->orWhere('created_by', $u->id)));
        $ids = (clone $q)->pluck('id');

        return [
            'held' => (clone $q)->where('status', 'ended')->count(),
            'upcoming' => Meeting::whereIn('id', $ids)->where('status', 'scheduled')->where('starts_at', '>', now())->count(),
            'people' => DB::table('meeting_attendance')->whereIn('meeting_id', $ids)->distinct()->count('internal_user_id'),
            'hours' => round(DB::table('meeting_attendance')->whereIn('meeting_id', $ids)->sum('seconds') / 3600, 1),
            'recordings' => DB::table('meeting_recordings')->whereIn('meeting_id', $ids)->where('state', 'published')->count(),
        ];
    }
}
