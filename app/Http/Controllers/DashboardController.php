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
    /** @var \Closure(string): bool whether a module is on for this institution */
    private \Closure $on;

    public function __invoke(Request $request, ModuleRegistry $modules, ReportCatalog $catalog): Response|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();

        // while an emergency exam is open, it is the only thing the student sees
        if ($user->user_type === 'student' && \App\Support\Exams\OpenExams::emergency($user)->isNotEmpty()) {
            return redirect('/my/exams/now');
        }

        $tid = tenant_id();
        $enabled = $modules->enabledFor(tenant());
        // every panel and figure belongs to a module: one switched off leaves the rest working
        $on = fn (string $m) => in_array($m, $enabled, true);
        $exams = $on('question_bank');
        $this->on = $on;
        $semester = DB::table('semesters')->where('tenant_id', $tid)->where('is_current', true)->first();
        $panels = [];

        if ($user->can('reports.all_departments')) {
            $panels['institution'] = [
                'students' => DB::table('users')->where('tenant_id', $tid)->where('user_type', 'student')->where('status', 'active')->count(),
                'staff' => DB::table('users')->where('tenant_id', $tid)->where('user_type', 'staff')->where('status', 'active')->count(),
                'courses' => ! $on('lms') ? null : ($semester ? DB::table('course_offerings')->where('semester_id', $semester->id)->count() : 0),
                'live_attempts' => ! $exams ? null : DB::table('attempts')->where('tenant_id', $tid)->whereIn('status', ['in_progress', 'paused', 'locked'])->count(),
                'open_incidents' => ! $on('browser_proctoring') ? null : DB::table('incidents')->where('tenant_id', $tid)->whereIn('status', ['open', 'investigating', 'escalated'])->count(),
                'results_waiting' => ! $on('question_bank') ? null : DB::table('results')->where('tenant_id', $tid)->whereIn('status', ['draft', 'moderated', 'approved'])->count(),
                'pending_registrations' => ! $on('lms') ? null : DB::table('enrolments')->where('tenant_id', $tid)->where('status', 'pending')->count(),
                'pending_exports' => DB::table('export_requests')->where('tenant_id', $tid)->where('status', 'pending')->count(),
                'failed_logins_24h' => DB::table('security_events')->where('tenant_id', $tid)->where('type', 'login_failed')->where('created_at', '>', now()->subDay())->count(),
                'last_backup' => DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->max('created_at'),
                'access_review_due' => ($last = DB::table('access_reviews')->where('tenant_id', $tid)->max('completed_at')) === null
                    || now()->diffInDays($last, true) > Policy::get('access_review_days'),
            ];
            $panels['upcoming'] = ! $exams ? null : DB::table('exam_sessions as s')->join('exams as e', 'e.id', '=', 's.exam_id')->leftJoin('venues as v', 'v.id', '=', 's.venue_id')
                ->where('s.tenant_id', $tid)->whereBetween('s.starts_at', [now()->subHours(3), now()->addDays(7)])->where('s.is_practice', false)
                ->orderBy('s.starts_at')->limit(12)
                ->get(['s.id', 'e.title', 's.starts_at', 's.ends_at', 's.status', 'v.name as venue',
                    DB::raw('(SELECT COUNT(*) FROM exam_candidates c WHERE c.exam_session_id = s.id) as candidates')]);
        }

        if ($on('lms') && $user->can('results.approve') && ! $user->can('reports.all_departments') && ($scope = $catalog->scope($user)) !== null) {
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
                'due' => ! $on('lms') ? null : DB::table('course_items as i')->join('course_offerings as o', 'o.id', '=', 'i.course_offering_id')->join('courses as c', 'c.id', '=', 'o.course_id')
                    ->whereIn('i.course_offering_id', $offerings)->whereIn('i.type', $exams ? ['assignment', 'quiz'] : ['assignment'])->where('i.visible', true)->whereNull('i.deleted_at')
                    ->whereBetween('i.due_at', [now(), now()->addDays(14)])->orderBy('i.due_at')->limit(8)->get(['i.id', 'i.title', 'i.type', 'i.due_at', 'c.code']),
                'exams' => ! $exams ? null : DB::table('exam_candidates as ec')->join('exam_sessions as s', 's.id', '=', 'ec.exam_session_id')->join('exams as e', 'e.id', '=', 's.exam_id')
                    ->where('ec.user_id', $user->id)->where('s.ends_at', '>', now())->whereNotIn('e.category', ['quiz'])->orderBy('s.starts_at')->limit(5)
                    ->get(['ec.id', 'e.title', 's.starts_at', 's.ends_at']),
                'unread' => $user->unreadNotifications()->count(),
            ];
        }

        // Exam day board, decisions waiting, and exams with their papers (exams office, proctors, approvers)
        if ($exams && $user->canAny(['exams.manage', 'exams.proctor', 'exams.invigilate', 'results.approve'])) {
            $panels['day'] = $this->examDay($tid);
        }
        if ($user->canAny(['exams.manage', 'results.approve', 'academics.manage', 'users.manage'])) {
            $panels['decisions'] = $this->decisions($user, $tid);
        }
        if ($exams && $user->can('exams.manage')) {
            $panels['exams'] = $this->examsInProgress();
        }
        if ($user->can('reports.all_departments')) {
            $panels['glance'] = $this->glance($tid);
        }

        if ($user->can('exams.manage') || $user->can('academics.manage') || $user->can('results.approve')) {
            $panels['casework'] = array_filter([
                'requests' => $on('student_requests') ? DB::table('exception_requests')->where('tenant_id', $tid)->where('status', 'pending')->count() : null,
                'hardware' => $exams ? DB::table('hardware_exceptions')->where('tenant_id', $tid)->where('status', 'pending')->count() : null,
                'anomalies' => $exams && $on('analytics') ? DB::table('attempt_anomalies')->where('tenant_id', $tid)->where('review', 'open')->count() : null,
            ], fn ($v) => $v !== null) ?: null;
        }

        return Inertia::render('dashboard', [
            'panels' => $panels,
            'layout' => ['hidden' => array_values((array) data_get($user->preferences, 'dashboard.hidden', [])), 'order' => array_values((array) data_get($user->preferences, 'dashboard.order', []))],
            'semester' => $semester ? DB::table('academic_sessions')->where('id', $semester->academic_session_id)->value('name').' '.$semester->name : null,
            'moduleCards' => collect($modules->all())->map(fn ($m, $key) => ['key' => $key, 'name' => $m['name'], 'description' => $m['description'], 'enabled' => in_array($key, $enabled, true)])->values(),
        ]);
    }

    /** Today's sittings with how many are writing / done, and the room-wide numbers for right now. */
    private function examDay(int $tid): array
    {
        $from = now()->startOfDay();
        $to = now()->endOfDay();
        $sessions = DB::table('exam_sessions as s')->join('exams as e', 'e.id', '=', 's.exam_id')->leftJoin('venues as v', 'v.id', '=', 's.venue_id')
            ->leftJoin('exam_groups as g', 'g.id', '=', 'e.exam_group_id')
            ->where('s.tenant_id', $tid)->where('s.is_practice', false)->where('s.starts_at', '<=', $to)->where('s.ends_at', '>=', $from)
            ->orderBy('s.starts_at')->limit(12)
            ->get(['s.id', 'e.id as exam_id', 'e.title', 'e.type', 'g.title as group_title', 's.starts_at', 's.ends_at', 's.mode', 'v.name as venue']);
        $ids = $sessions->pluck('id');
        $cands = DB::table('exam_candidates')->whereIn('exam_session_id', $ids)->groupBy('exam_session_id')->pluck(DB::raw('count(*)'), 'exam_session_id');
        $att = DB::table('attempts')->whereIn('exam_session_id', $ids)->select('exam_session_id', 'status', DB::raw('count(*) as n'))->groupBy('exam_session_id', 'status')->get()->groupBy('exam_session_id');
        $rows = $sessions->map(function ($s) use ($cands, $att) {
            $a = ($att[$s->id] ?? collect())->pluck('n', 'status');
            $writing = (int) (($a['in_progress'] ?? 0) + ($a['paused'] ?? 0) + ($a['locked'] ?? 0));
            $done = (int) (($a['submitted'] ?? 0) + ($a['auto_submitted'] ?? 0) + ($a['terminated'] ?? 0));
            $label = $s->group_title && str_starts_with($s->title, $s->group_title.' · ') ? substr($s->title, strlen($s->group_title) + 3) : null;

            return ['id' => $s->id, 'exam_id' => $s->exam_id, 'title' => $label ? $s->group_title : $s->title, 'paper' => $label, 'type' => $s->type,
                'starts_at' => \Illuminate\Support\Carbon::parse($s->starts_at)->toIso8601String(), 'ends_at' => \Illuminate\Support\Carbon::parse($s->ends_at)->toIso8601String(),
                'where' => $s->venue ?? ($s->mode === 'remote' ? 'Online' : 'Hall'), 'candidates' => (int) ($cands[$s->id] ?? 0), 'writing' => $writing, 'submitted' => $done];
        });
        $candidates = $rows->sum('candidates');
        $writing = $rows->sum('writing');
        $submitted = $rows->sum('submitted');

        return [
            'sittings' => $rows->values(),
            'candidates' => $candidates,
            'writing' => $writing,
            'submitted' => $submitted,
            'not_started' => max(0, $candidates - $writing - $submitted),
            'need_help' => DB::table('candidate_messages as m')->join('attempts as a', 'a.id', '=', 'm.attempt_id')->whereIn('a.exam_session_id', $ids)
                ->where('m.direction', 'to_proctor')->whereNull('m.acknowledged_at')->count(),
            'flagged' => DB::table('incidents')->where('tenant_id', $tid)->whereIn('exam_session_id', $ids)->whereIn('status', ['open', 'investigating', 'escalated'])->count(),
        ];
    }

    /** What is waiting for this person: flags to review, papers to approve, results to publish, requests to decide. */
    private function decisions($user, int $tid): array
    {
        $items = [];
        $on = $this->on;
        if ($on('browser_proctoring') && $user->canAny(['exams.manage', 'exams.proctor'])) {
            foreach (DB::table('incidents as i')->leftJoin('attempts as a', 'a.id', '=', 'i.attempt_id')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->leftJoin('exams as e', 'e.id', '=', 'a.exam_id')
                ->where('i.tenant_id', $tid)->whereIn('i.status', ['open', 'investigating', 'escalated'])
                ->orderByRaw("CASE i.severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->latest('i.id')->limit(4)
                ->get(['i.id', 'i.title', 'i.severity', 'i.created_at', 'u.name', 'e.title as exam']) as $r) {
                $items[] = ['kind' => 'exam_day', 'tone' => in_array($r->severity, ['high', 'critical'], true) ? 'critical' : 'warning', 'icon' => 'eye',
                    'title' => $r->title.($r->name ? ' — '.$r->name : ''), 'detail' => trim(($r->exam ?? '').' · '.\Illuminate\Support\Carbon::parse($r->created_at)->diffForHumans(), ' ·'),
                    'href' => '/incidents/'.$r->id, 'action' => 'Review'];
            }
        }
        if ($on('question_bank') && $user->canAny(['exams.manage', 'questions.moderate'])) {
            foreach (DB::table('exams as e')->leftJoin('users as u', 'u.id', '=', 'e.created_by')->where('e.tenant_id', $tid)->where('e.status', 'submitted')
                ->where('e.created_by', '!=', $user->id)->whereNull('e.deleted_at')->latest('e.updated_at')->limit(4)->get(['e.id', 'e.title', 'u.name']) as $r) {
                $items[] = ['kind' => 'approvals', 'tone' => 'warning', 'icon' => 'check', 'title' => $r->title.' is waiting for your approval',
                    'detail' => 'Set by '.($r->name ?? 'a colleague'), 'href' => '/exams/'.$r->id, 'action' => 'Approve'];
            }
        }
        if ($on('results') && $user->can('results.approve')) {
            foreach (DB::table('results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->where('r.tenant_id', $tid)->where('r.status', 'approved')
                ->groupBy('e.id', 'e.title')->limit(3)->get(['e.id', 'e.title', DB::raw('count(*) as n')]) as $r) {
                $items[] = ['kind' => 'approvals', 'tone' => 'warning', 'icon' => 'send', 'title' => 'Publish '.$r->n.' '.$r->title.' '.($r->n == 1 ? 'result' : 'results'),
                    'detail' => 'Approved and ready for students', 'href' => '/results/'.$r->id, 'action' => 'Publish'];
            }
        }
        if ($user->canAny(['exams.manage', 'academics.manage', 'results.approve', 'users.manage'])) {
            foreach (! $on('student_requests') ? [] : DB::table('exception_requests as x')->join('users as u', 'u.id', '=', 'x.user_id')->where('x.tenant_id', $tid)->where('x.status', 'pending')
                ->latest('x.id')->limit(3)->get(['x.id', 'x.subject', 'x.type', 'u.name']) as $r) {
                $items[] = ['kind' => 'requests', 'tone' => 'info', 'icon' => 'timer', 'title' => $r->subject.' — '.$r->name,
                    'detail' => str_replace('_', ' ', ucfirst($r->type)), 'href' => '/admin/exceptions', 'action' => 'Decide'];
            }
            foreach (! $on('results') ? [] : DB::table('appeals as a')->join('users as u', 'u.id', '=', 'a.user_id')->join('results as r', 'r.id', '=', 'a.result_id')->join('exams as e', 'e.id', '=', 'r.exam_id')
                ->where('a.tenant_id', $tid)->where('a.status', 'submitted')
                ->latest('a.id')->limit(2)->get(['a.id', 'a.kind', 'u.name', 'e.id as exam_id', 'e.title']) as $r) {
                $items[] = ['kind' => 'requests', 'tone' => 'info', 'icon' => 'scale', 'title' => 'Result '.$r->kind.' request — '.$r->name,
                    'detail' => $r->title, 'href' => '/results/'.$r->exam_id, 'action' => 'Open'];
            }
        }

        return $items;
    }

    /** Exams with papers that are running, coming up, or still being set up. */
    private function examsInProgress(): array
    {
        $labels = \App\Models\ExamGroup::PAPERS;

        return \App\Models\ExamGroup::query()->whereHas('papers', fn ($q) => $q->whereNotIn('status', ['archived', 'results_published']))
            ->with(['papers' => fn ($q) => $q->with(['sessions' => fn ($s) => $s->orderBy('starts_at')])->withCount('attempts')])
            ->latest('updated_at')->limit(3)->get()
            ->map(function ($g) use ($labels) {
                $paperIds = $g->papers->pluck('id');
                $cand = DB::table('exam_candidates as c')->join('exam_sessions as s', 's.id', '=', 'c.exam_session_id')->whereIn('s.exam_id', $paperIds)->groupBy('s.exam_id')->pluck(DB::raw('count(*)'), 's.exam_id');
                $att = DB::table('attempts')->whereIn('exam_id', $paperIds)->select('exam_id', 'status', DB::raw('count(*) as n'))->groupBy('exam_id', 'status')->get()->groupBy('exam_id');

                return ['id' => $g->id, 'title' => $g->title, 'students' => (int) $cand->max(), 'papers' => $g->papers->map(function ($p) use ($labels, $cand, $att) {
                    $a = ($att[$p->id] ?? collect())->pluck('n', 'status');
                    $first = $p->sessions->first();

                    return ['id' => $p->id, 'type' => $p->type, 'label' => $labels[$p->type] ?? $p->type, 'status' => $p->status,
                        'starts_at' => $first?->starts_at?->toIso8601String(), 'ends_at' => $first?->ends_at?->toIso8601String(),
                        'candidates' => (int) ($cand[$p->id] ?? 0), 'writing' => (int) ($a['in_progress'] ?? 0), 'submitted' => (int) (($a['submitted'] ?? 0) + ($a['auto_submitted'] ?? 0))];
                })->values()];
            })->all();
    }

    /** Four figures for the institution, each with a short trend. */
    private function glance(int $tid): array
    {
        $students = DB::table('users')->where('tenant_id', $tid)->where('user_type', 'student')->where('status', 'active');
        $weeks = collect(range(7, 0))->map(fn ($w) => (clone $students)->where('created_at', '<=', now()->subWeeks($w))->count());
        $passMin = Policy::get('grading_scale')[Policy::get('pass_grade_min')] ?? 40;
        $on = $this->on;
        $recent = ! $on('question_bank') ? collect() : DB::table('results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->where('r.tenant_id', $tid)->where('r.status', 'published')
            ->select('e.id', DB::raw('MAX(r.published_at) as at'), DB::raw('AVG(CASE WHEN r.max_score > 0 AND r.raw_score * 100 / r.max_score >= '.(float) $passMin.' THEN 100 ELSE 0 END) as pass'))
            ->groupBy('e.id')->orderByDesc('at')->limit(5)->get()->reverse()->values();

        return [
            'students' => (int) $weeks->last(),
            'students_week' => (int) ($weeks->last() - $weeks[count($weeks) - 2]),
            'students_trend' => $weeks->values(),
            'pass_rate' => $recent->isNotEmpty() ? (int) round($recent->avg('pass')) : null,
            'pass_trend' => $recent->pluck('pass')->map(fn ($v) => (int) round((float) $v))->values(),
            'results_to_approve' => ! $on('results') ? null : DB::table('results')->where('tenant_id', $tid)->whereIn('status', ['draft', 'moderated'])->count(),
            'results_ready' => ! $on('results') ? null : DB::table('results')->where('tenant_id', $tid)->where('status', 'approved')->count(),
            'open_incidents' => ! $on('browser_proctoring') ? null : DB::table('incidents')->where('tenant_id', $tid)->whereIn('status', ['open', 'investigating', 'escalated'])->count(),
            'urgent_incidents' => ! $on('browser_proctoring') ? null : DB::table('incidents')->where('tenant_id', $tid)->whereIn('status', ['open', 'escalated'])->whereIn('severity', ['high', 'critical'])->count(),
        ];
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

    /**
     * How a person arranges the cards on a page (dashboard, courses, exams...): order, hidden, minimised and
     * full-width cards. Saved to their account, one entry per page.
     */
    public function saveArrangement(Request $request, string $page): \Illuminate\Http\RedirectResponse
    {
        abort_unless(preg_match('/^[a-z][a-z0-9-]{1,39}$/', $page), 404);
        $key = ['array', 'max:200'];
        $item = ['string', 'regex:/^[A-Za-z0-9_:.-]{1,60}$/'];
        $data = $request->validate(['order' => $key, 'order.*' => $item, 'hidden' => $key, 'hidden.*' => $item, 'collapsed' => $key, 'collapsed.*' => $item, 'wide' => $key, 'wide.*' => $item, 'narrow' => $key, 'narrow.*' => $item]);
        $u = $request->user();
        $prefs = (array) $u->preferences;
        $layouts = (array) ($prefs['layouts'] ?? []);
        $layouts[$page] = collect(['order', 'hidden', 'collapsed', 'wide', 'narrow'])->mapWithKeys(fn ($k) => [$k => array_values(array_unique($data[$k] ?? []))])->all();
        // at most 30 pages are remembered, the most recently arranged kept
        $prefs['layouts'] = array_slice([$page => $layouts[$page]] + $layouts, 0, 30, true);
        if ($page === 'dashboard') {
            $prefs['dashboard'] = ['hidden' => $layouts[$page]['hidden'], 'order' => $layouts[$page]['order']];
        }
        $u->forceFill(['preferences' => $prefs])->save();

        return back();
    }
}
