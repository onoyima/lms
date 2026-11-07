<?php

namespace App\Http\Controllers;

use App\Services\Modules\ModuleRegistry;
use App\Services\Reports\ReportCatalog;
use App\Support\Policy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One dashboard that adapts to the person (Target 215; LMS 164-166, 179, 200, 228):
 * institution indicators for the exams office / admins, faculty or department views for
 * Deans and HODs, teaching for lecturers, and what is due for students.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ModuleRegistry $modules, ReportCatalog $catalog): Response
    {
        $user = $request->user();
        $tid = tenant_id();
        $enabled = $modules->enabledFor(tenant());
        $semester = DB::table('semesters')->where('tenant_id', $tid)->where('is_current', true)->first();
        $panels = [];

        if ($user->can('reports.all_departments')) {
            $panels['institution'] = [
                'students' => DB::table('users')->where('tenant_id', $tid)->where('user_type', 'student')->where('status', 'active')->count(),
                'staff' => DB::table('users')->where('tenant_id', $tid)->where('user_type', 'staff')->where('status', 'active')->count(),
                'courses' => $semester ? DB::table('course_offerings')->where('semester_id', $semester->id)->count() : 0,
                'live_attempts' => DB::table('attempts')->where('tenant_id', $tid)->whereIn('status', ['in_progress', 'paused', 'locked'])->count(),
                'open_incidents' => DB::table('incidents')->where('tenant_id', $tid)->whereIn('status', ['open', 'investigating', 'escalated'])->count(),
                'results_waiting' => DB::table('results')->where('tenant_id', $tid)->whereIn('status', ['draft', 'moderated', 'approved'])->count(),
                'pending_registrations' => DB::table('enrolments')->where('tenant_id', $tid)->where('status', 'pending')->count(),
                'pending_exports' => DB::table('export_requests')->where('tenant_id', $tid)->where('status', 'pending')->count(),
                'failed_logins_24h' => DB::table('security_events')->where('tenant_id', $tid)->where('type', 'login_failed')->where('created_at', '>', now()->subDay())->count(),
                'last_backup' => DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->max('created_at'),
                'access_review_due' => ($last = DB::table('access_reviews')->where('tenant_id', $tid)->max('completed_at')) === null
                    || now()->diffInDays($last, true) > Policy::get('access_review_days'),
            ];
            $panels['upcoming'] = DB::table('exam_sessions as s')->join('exams as e', 'e.id', '=', 's.exam_id')->leftJoin('venues as v', 'v.id', '=', 's.venue_id')
                ->where('s.tenant_id', $tid)->whereBetween('s.starts_at', [now()->subHours(3), now()->addDays(7)])->where('s.is_practice', false)
                ->orderBy('s.starts_at')->limit(12)
                ->get(['s.id', 'e.title', 's.starts_at', 's.ends_at', 's.status', 'v.name as venue',
                    DB::raw('(SELECT COUNT(*) FROM exam_candidates c WHERE c.exam_session_id = s.id) as candidates')]);
        }

        if ($user->can('results.approve') && ! $user->can('reports.all_departments') && ($scope = $catalog->scope($user)) !== null) {
            $grades = DB::table('course_grades as g')->join('course_offerings as o', 'o.id', '=', 'g.course_offering_id')->join('courses as c', 'c.id', '=', 'o.course_id')
                ->whereIn('c.department_id', $scope)->when($semester, fn ($q) => $q->where('o.semester_id', $semester->id));
            $total = (clone $grades)->count();
            $passMin = Policy::get('grading_scale')[Policy::get('pass_grade_min')] ?? 40;
            $panels['oversight'] = [
                'scope' => $user->can('reports.faculty') ? 'faculty' : 'department',
                'courses' => DB::table('course_offerings as o')->join('courses as c', 'c.id', '=', 'o.course_id')->whereIn('c.department_id', $scope)
                    ->when($semester, fn ($q) => $q->where('o.semester_id', $semester->id))->count(),
                'reviews_waiting' => DB::table('course_offerings as o')->join('courses as c', 'c.id', '=', 'o.course_id')->whereIn('c.department_id', $scope)->where('o.review_status', 'submitted')->count(),
                'results_to_approve' => (clone $grades)->where('g.status', 'draft')->distinct()->count('g.course_offering_id'),
                'pass_rate' => $total ? round((clone $grades)->where('g.total', '>=', $passMin)->count() / $total * 100) : null,
                'marking_backlog' => DB::table('assignment_submissions as s')->join('course_items as i', 'i.id', '=', 's.course_item_id')->join('course_offerings as o', 'o.id', '=', 'i.course_offering_id')
                    ->join('courses as c', 'c.id', '=', 'o.course_id')->whereIn('c.department_id', $scope)->where('s.status', 'submitted')->count(),
                'courses_needing_attention' => DB::table('course_offerings as o')->join('courses as c', 'c.id', '=', 'o.course_id')->whereIn('c.department_id', $scope)
                    ->when($semester, fn ($q) => $q->where('o.semester_id', $semester->id))
                    ->whereRaw('(SELECT COUNT(*) FROM course_items i WHERE i.course_offering_id = o.id AND i.deleted_at IS NULL) < 3')
                    ->limit(10)->get(['o.id', 'c.code', 'c.title']),
            ];
        }

        $mine = DB::table('course_offering_staff')->where('user_id', $user->id)->pluck('course_offering_id');
        if ($mine->isNotEmpty() && in_array('lms', $enabled, true)) {
            $panels['teaching'] = [
                'courses' => $mine->count(),
                'to_mark' => DB::table('assignment_submissions as s')->join('course_items as i', 'i.id', '=', 's.course_item_id')->whereIn('i.course_offering_id', $mine)->where('s.status', 'submitted')->count(),
                'unanswered_threads' => DB::table('forum_threads as t')->join('course_items as i', 'i.id', '=', 't.course_item_id')->whereIn('i.course_offering_id', $mine)->where('t.posts_count', 1)->count(),
                'students' => DB::table('enrolments')->whereIn('course_offering_id', $mine)->where('status', 'active')->count(),
            ];
        }

        if ($user->user_type === 'student') {
            $offerings = DB::table('enrolments')->where('user_id', $user->id)->where('status', 'active')->pluck('course_offering_id');
            $panels['student'] = [
                'courses' => $offerings->count(),
                'due' => DB::table('course_items as i')->join('course_offerings as o', 'o.id', '=', 'i.course_offering_id')->join('courses as c', 'c.id', '=', 'o.course_id')
                    ->whereIn('i.course_offering_id', $offerings)->whereIn('i.type', ['assignment', 'quiz'])->where('i.visible', true)->whereNull('i.deleted_at')
                    ->whereBetween('i.due_at', [now(), now()->addDays(14)])->orderBy('i.due_at')->limit(8)->get(['i.id', 'i.title', 'i.type', 'i.due_at', 'c.code']),
                'exams' => DB::table('exam_candidates as ec')->join('exam_sessions as s', 's.id', '=', 'ec.exam_session_id')->join('exams as e', 'e.id', '=', 's.exam_id')
                    ->where('ec.user_id', $user->id)->where('s.ends_at', '>', now())->whereNotIn('e.category', ['quiz'])->orderBy('s.starts_at')->limit(5)
                    ->get(['ec.id', 'e.title', 's.starts_at']),
                'unread' => $user->unreadNotifications()->count(),
            ];
        }

        if ($user->can('exams.manage') || $user->can('academics.manage') || $user->can('results.approve')) {
            $panels['casework'] = [
                'requests' => DB::table('exception_requests')->where('tenant_id', $tid)->where('status', 'pending')->count(),
                'hardware' => DB::table('hardware_exceptions')->where('tenant_id', $tid)->where('status', 'pending')->count(),
                'anomalies' => DB::table('attempt_anomalies')->where('tenant_id', $tid)->where('review', 'open')->count(),
            ];
        }

        return Inertia::render('dashboard', [
            'panels' => $panels,
            'layout' => ['hidden' => array_values((array) data_get($user->preferences, 'dashboard.hidden', [])), 'order' => array_values((array) data_get($user->preferences, 'dashboard.order', []))],
            'semester' => $semester ? DB::table('academic_sessions')->where('id', $semester->academic_session_id)->value('name').' '.$semester->name : null,
            'moduleCards' => collect($modules->all())->map(fn ($m, $key) => ['key' => $key, 'name' => $m['name'], 'description' => $m['description'], 'enabled' => in_array($key, $enabled, true)])->values(),
        ]);
    }

    /** Each person chooses which dashboard panels and figures they see, and in what order (LMS 200). */
    public function saveLayout(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['hidden' => ['array', 'max:40'], 'hidden.*' => ['string', 'regex:/^[a-z_:]{2,40}$/'], 'order' => ['array', 'max:20'], 'order.*' => ['string', 'regex:/^[a-z_]{2,30}$/']]);
        $u = $request->user();
        $prefs = (array) $u->preferences;
        $prefs['dashboard'] = ['hidden' => array_values(array_unique($data['hidden'] ?? [])), 'order' => array_values(array_unique($data['order'] ?? []))];
        $u->forceFill(['preferences' => $prefs])->save();

        return back();
    }
}
