<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Policy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every report in one place (Target 62-67, 107, 193-197; LMS 159, 174-175, 189-199, 214).
 * Each report declares who may run it, whether it contains personal data, and returns
 * columns + rows. HOD/Dean users are limited to their own department/faculty automatically.
 */
class ReportCatalog
{
    public function __construct(private AuditLogger $audit) {}

    public function definitions(): array
    {
        return [
            'registrations' => ['Course registrations and enrolment', 'Registration', ['academics.manage', 'results.approve', 'exams.manage'], false],
            'student_registrations' => ['Students and their registered units', 'Registration', ['academics.manage', 'results.approve'], true],
            'exam_attendance' => ['Examination attendance and absentees', 'Examinations', ['exams.manage', 'exams.invigilate', 'results.approve'], true],
            'exam_submissions' => ['Candidate submissions and interruptions', 'Examinations', ['exams.manage', 'results.approve'], false],
            'incidents' => ['Malpractice and incident report', 'Examinations', ['exams.manage', 'exams.proctor', 'audit.view'], false],
            'incident_trends' => ['Incident statistics and trends', 'Examinations', ['exams.manage', 'audit.view'], false],
            'post_exam_review' => ['Post-exam review: how each sitting went', 'Examinations', ['exams.manage', 'audit.view'], false],
            'proctor_workload' => ['Proctor workload and performance', 'Examinations', ['exams.manage'], false],
            'course_performance' => ['Course performance, pass and failure rates', 'Results', ['results.approve', 'exams.manage', 'academics.manage'], false],
            'department_performance' => ['Department and faculty comparison', 'Results', ['results.approve', 'exams.manage', 'academics.manage'], false],
            'result_changes' => ['Result change history', 'Results', ['results.publish', 'audit.view'], true],
            'lecturer_activity' => ['Lecturer activity and assessment', 'Teaching', ['results.approve', 'academics.manage'], false],
            'staff_workload' => ['Staff workload and course allocation', 'Teaching', ['results.approve', 'academics.manage'], false],
            'course_coverage' => ['Course coverage and assessment completion', 'Teaching', ['results.approve', 'academics.manage'], false],
            'student_engagement' => ['Student engagement and course completion', 'Teaching', ['results.approve', 'academics.manage'], true],
            'compliance' => ['Audit and compliance summary', 'Governance', ['audit.view'], false],
            'access_review' => ['Privileged access review', 'Governance', ['users.manage', 'audit.view'], true],
        ];
    }

    public function allowed(User $user): array
    {
        return collect($this->definitions())->filter(fn ($d) => collect($d[2])->contains(fn ($p) => $user->can($p)))->all();
    }

    /** Department ids the user is limited to (null = whole institution). */
    public function scope(User $user): ?array
    {
        if ($user->can('reports.all_departments') || $user->is_platform_admin) {
            return null;
        }
        $p = DB::table('staff_profiles')->where('user_id', $user->id)->first();
        if ($user->can('reports.faculty') && $p?->faculty_id) {
            return DB::table('departments')->where('faculty_id', $p->faculty_id)->pluck('id')->all();
        }
        if ($p?->department_id) {
            return [$p->department_id];
        }

        return [0]; // no placement: sees nothing department-bound
    }

    /** @return array{columns: list<string>, rows: list<array>, personal: bool, title: string} */
    public function run(string $key, array $f, User $user, int $limit = 100000): array
    {
        $def = $this->allowed($user)[$key] ?? abort(403, 'You cannot run this report.');
        $f['departments'] = $this->scope($user);
        $method = 'r'.str_replace('_', '', ucwords($key, '_'));
        [$columns, $rows] = $this->{$method}($f, $limit);
        $this->audit->record('report.run', null, ['report' => $key, 'filters' => array_diff_key($f, ['departments' => 1]), 'rows' => count($rows)]);

        return ['columns' => $columns, 'rows' => $rows, 'personal' => $def[3], 'title' => $def[0]];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function offerings(array $f): Builder
    {
        return DB::table('course_offerings as o')->join('courses as c', 'c.id', '=', 'o.course_id')
            ->leftJoin('departments as d', 'd.id', '=', 'c.department_id')->leftJoin('semesters as s', 's.id', '=', 'o.semester_id')
            ->where('o.tenant_id', tenant_id())
            ->when($f['semester_id'] ?? null, fn ($q, $v) => $q->where('o.semester_id', $v))
            ->when($f['session_id'] ?? null, fn ($q, $v) => $q->where('s.academic_session_id', $v))
            ->when($f['faculty_id'] ?? null, fn ($q, $v) => $q->where('d.faculty_id', $v))
            ->when($f['department_id'] ?? null, fn ($q, $v) => $q->where('c.department_id', $v))
            ->when($f['course_id'] ?? null, fn ($q, $v) => $q->where('c.id', $v))
            ->when($f['level'] ?? null, fn ($q, $v) => $q->where('c.level', $v))
            ->when($f['departments'] !== null, fn ($q) => $q->whereIn('c.department_id', $f['departments']));
    }

    private function exams(array $f): Builder
    {
        return DB::table('exams as e')->leftJoin('courses as c', 'c.id', '=', 'e.course_id')->leftJoin('departments as d', 'd.id', '=', 'c.department_id')
            ->leftJoin('semesters as s', 's.id', '=', 'e.semester_id')
            ->where('e.tenant_id', tenant_id())->whereNull('e.deleted_at')
            ->when($f['semester_id'] ?? null, fn ($q, $v) => $q->where('e.semester_id', $v))
            ->when($f['session_id'] ?? null, fn ($q, $v) => $q->where('s.academic_session_id', $v))
            ->when($f['faculty_id'] ?? null, fn ($q, $v) => $q->where('d.faculty_id', $v))
            ->when($f['department_id'] ?? null, fn ($q, $v) => $q->where('c.department_id', $v))
            ->when($f['course_id'] ?? null, fn ($q, $v) => $q->where('c.id', $v))
            ->when($f['level'] ?? null, fn ($q, $v) => $q->where('c.level', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('e.created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('e.created_at', '<=', $v.' 23:59:59'))
            ->when($f['departments'] !== null, fn ($q) => $q->whereIn('c.department_id', $f['departments']));
    }

    private function pct($a, $b): ?float
    {
        return $b ? round($a / $b * 100, 1) : null;
    }

    // ── reports ──────────────────────────────────────────────────────────

    private function rRegistrations(array $f, int $limit): array
    {
        $rows = $this->offerings($f)->leftJoin('enrolments as en', 'en.course_offering_id', '=', 'o.id')
            ->groupBy('o.id', 'c.code', 'c.title', 'd.name', 'c.level', 'o.capacity', 's.name')
            ->selectRaw("c.code, c.title, d.name as department, c.level, s.name as semester, o.capacity,
                SUM(CASE WHEN en.status IN ('active','completed') THEN 1 ELSE 0 END) registered,
                SUM(CASE WHEN en.status = 'pending' THEN 1 ELSE 0 END) pending,
                SUM(CASE WHEN en.status = 'dropped' THEN 1 ELSE 0 END) dropped,
                SUM(CASE WHEN en.category = 'carryover' THEN 1 ELSE 0 END) carryover")
            ->orderBy('c.code')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Code', 'Title', 'Department', 'Level', 'Semester', 'Capacity', 'Registered', 'Pending', 'Dropped', 'Carry-over'], $rows];
    }

    private function rStudentRegistrations(array $f, int $limit): array
    {
        $rows = $this->offerings($f)->join('enrolments as en', 'en.course_offering_id', '=', 'o.id')->join('users as u', 'u.id', '=', 'en.user_id')
            ->leftJoin('student_profiles as sp', 'sp.user_id', '=', 'u.id')->leftJoin('programmes as p', 'p.id', '=', 'sp.programme_id')
            ->whereIn('en.status', ['active', 'pending', 'completed'])
            ->groupBy('u.id', 'u.identifier', 'u.name', 'p.name', 'sp.level', 'sp.academic_status')
            ->selectRaw('u.identifier, u.name, p.name as programme, sp.level, sp.academic_status, COUNT(*) courses, SUM(c.credit_units) units')
            ->orderBy('u.name')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Matric no.', 'Name', 'Programme', 'Level', 'Status', 'Courses', 'Units'], $rows];
    }

    private function rExamAttendance(array $f, int $limit): array
    {
        $rows = $this->exams($f)->join('exam_sessions as es', 'es.exam_id', '=', 'e.id')->join('exam_candidates as ec', 'ec.exam_session_id', '=', 'es.id')
            ->join('users as u', 'u.id', '=', 'ec.user_id')->leftJoin('venues as v', 'v.id', '=', 'es.venue_id')
            ->leftJoin('attempts as a', fn ($j) => $j->on('a.exam_candidate_id', '=', 'ec.id'))
            ->when($f['only_absent'] ?? false, fn ($q) => $q->whereNull('a.id'))
            ->selectRaw("e.title as exam, es.starts_at, v.name as venue, u.identifier, u.name, ec.attendance, ec.checked_in_at,
                CASE WHEN a.id IS NULL THEN 'absent' ELSE a.status END as outcome, a.submitted_at")
            ->orderBy('es.starts_at')->orderBy('u.name')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Exam', 'Starts', 'Venue', 'Matric no.', 'Name', 'Attendance', 'Checked in', 'Outcome', 'Submitted'], $rows];
    }

    private function rExamSubmissions(array $f, int $limit): array
    {
        $rows = $this->exams($f)->leftJoin('exam_sessions as es', 'es.exam_id', '=', 'e.id')->leftJoin('exam_candidates as ec', 'ec.exam_session_id', '=', 'es.id')
            ->leftJoin('attempts as a', 'a.exam_candidate_id', '=', 'ec.id')
            ->groupBy('e.id', 'e.title', 'e.type')
            ->selectRaw("e.title, e.type, COUNT(DISTINCT ec.id) candidates, COUNT(DISTINCT a.id) started,
                SUM(CASE WHEN a.status='submitted' THEN 1 ELSE 0 END) submitted, SUM(CASE WHEN a.status='auto_submitted' THEN 1 ELSE 0 END) auto_submitted,
                SUM(CASE WHEN a.status='terminated' THEN 1 ELSE 0 END) n_terminated, SUM(CASE WHEN a.status IN ('in_progress','paused','locked') THEN 1 ELSE 0 END) still_open,
                SUM(CASE WHEN a.paused_seconds > 0 THEN 1 ELSE 0 END) interrupted, SUM(COALESCE(a.violation_count,0)) violations")
            ->orderBy('e.title')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Exam', 'Type', 'Candidates', 'Started', 'Submitted', 'Auto-submitted', 'Terminated', 'Still open', 'Interrupted', 'Violations'], $rows];
    }

    private function rIncidents(array $f, int $limit): array
    {
        $rows = $this->exams($f)->join('exam_sessions as es', 'es.exam_id', '=', 'e.id')->join('incidents as i', 'i.exam_session_id', '=', 'es.id')
            ->leftJoin('attempts as a', 'a.id', '=', 'i.attempt_id')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->selectRaw('e.title as exam, i.id, i.type, i.severity, i.status, u.identifier, i.created_at, i.resolved_at, i.resolution')
            ->orderByDesc('i.created_at')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Exam', 'Case', 'Type', 'Severity', 'Status', 'Candidate', 'Opened', 'Resolved', 'Outcome'], $rows];
    }

    private function rIncidentTrends(array $f, int $limit): array
    {
        $rows = $this->exams($f)->join('exam_sessions as es', 'es.exam_id', '=', 'e.id')->join('incidents as i', 'i.exam_session_id', '=', 'es.id')
            ->groupBy(DB::raw('DATE(i.created_at)'), 'i.type')
            ->selectRaw("DATE(i.created_at) day, i.type, COUNT(*) cases, SUM(CASE WHEN i.severity IN ('high','critical') THEN 1 ELSE 0 END) serious,
                SUM(CASE WHEN i.status IN ('resolved','dismissed') THEN 1 ELSE 0 END) closed")
            ->orderBy('day')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Day', 'Type', 'Cases', 'High or critical', 'Closed'], $rows];
    }

    /** One row per sitting: attendance, completion, support, disruptions and integrity signals (Target 66, 100, 153, 214). */
    private function rPostExamReview(array $f, int $limit): array
    {
        $sessions = $this->exams($f)->join('exam_sessions as es', 'es.exam_id', '=', 'e.id')->where('es.is_practice', false)->where('es.starts_at', '<', now())
            ->orderByDesc('es.starts_at')->limit($limit)->get(['es.id', 'e.title', 'es.name', 'es.starts_at']);
        $ids = $sessions->pluck('id');
        $count = fn ($q) => $q->pluck('n', 'sid');
        $cand = $count(DB::table('exam_candidates')->whereIn('exam_session_id', $ids)->groupBy('exam_session_id')->selectRaw("exam_session_id sid, COUNT(*) n"));
        $att = DB::table('attempts')->whereIn('exam_session_id', $ids)->groupBy('exam_session_id')
            ->selectRaw("exam_session_id sid, COUNT(*) started, SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) submitted, SUM(CASE WHEN status='auto_submitted' THEN 1 ELSE 0 END) auto,
                SUM(CASE WHEN risk_score >= 50 THEN 1 ELSE 0 END) high_risk, SUM(CASE WHEN paused_seconds > 0 THEN 1 ELSE 0 END) interrupted")->get()->keyBy('sid');
        $help = DB::table('candidate_messages as m')->join('attempts as a', 'a.id', '=', 'm.attempt_id')->whereIn('a.exam_session_id', $ids)->where('m.kind', 'help')->groupBy('a.exam_session_id')
            ->selectRaw('a.exam_session_id sid, COUNT(*) asked, SUM(CASE WHEN m.acknowledged_at IS NOT NULL THEN 1 ELSE 0 END) answered')->get()->keyBy('sid');
        $events = DB::table('proctoring_events as p')->join('attempts as a', 'a.id', '=', 'p.attempt_id')->whereIn('a.exam_session_id', $ids)
            ->whereIn('p.type', ['identity_mismatch', 'network_offline', 'media_disconnected', 'second_camera_lost', 'remote_desktop', 'vm_detected', 'multiple_faces'])
            ->groupBy('a.exam_session_id', 'p.type')->selectRaw('a.exam_session_id sid, p.type, COUNT(*) n')->get()->groupBy('sid');
        $incidents = $count(DB::table('incidents')->whereIn('exam_session_id', $ids)->groupBy('exam_session_id')->selectRaw('exam_session_id sid, COUNT(*) n'));
        $offline = $count(DB::table('audit_logs')->where('action', 'proctor.offline_alert')->where('subject_type', \App\Models\ExamSession::class)->whereIn('subject_id', $ids)
            ->groupBy('subject_id')->selectRaw('subject_id sid, COUNT(*) n'));

        $rows = $sessions->map(function ($s) use ($cand, $att, $help, $events, $incidents, $offline) {
            $a = $att[$s->id] ?? null;
            $h = $help[$s->id] ?? null;
            $ev = ($events[$s->id] ?? collect())->pluck('n', 'type');

            return [
                'exam' => $s->title.($s->name ? ' · '.$s->name : ''), 'starts_at' => $s->starts_at, 'candidates' => (int) ($cand[$s->id] ?? 0), 'started' => (int) ($a->started ?? 0),
                'submitted' => (int) ($a->submitted ?? 0), 'auto' => (int) ($a->auto ?? 0), 'interrupted' => (int) ($a->interrupted ?? 0),
                'help' => (int) ($h->asked ?? 0), 'help_answered' => $h ? $this->pct($h->answered, $h->asked) : null,
                'disconnections' => (int) (($ev['network_offline'] ?? 0) + ($ev['media_disconnected'] ?? 0) + ($ev['second_camera_lost'] ?? 0)),
                'identity' => (int) ($ev['identity_mismatch'] ?? 0), 'environment' => (int) (($ev['remote_desktop'] ?? 0) + ($ev['vm_detected'] ?? 0) + ($ev['multiple_faces'] ?? 0)),
                'high_risk' => (int) ($a->high_risk ?? 0), 'incidents' => (int) ($incidents[$s->id] ?? 0), 'proctor_offline' => (int) ($offline[$s->id] ?? 0),
            ];
        })->all();

        return [['Sitting', 'Started at', 'Candidates', 'Started', 'Submitted', 'Auto-submitted', 'Interrupted', 'Help requests', 'Help answered %', 'Disconnections',
            'Identity mismatches', 'Remote/VM/extra faces', 'High-risk attempts', 'Incidents', 'Proctor offline alerts'], $rows];
    }

    private function rProctorWorkload(array $f, int $limit): array
    {
        $rows = DB::table('proctor_assignments as pa')->join('users as u', 'u.id', '=', 'pa.user_id')->join('exam_sessions as es', 'es.id', '=', 'pa.exam_session_id')
            ->where('es.tenant_id', tenant_id())
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('es.starts_at', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->where('es.starts_at', '<=', $v.' 23:59:59'))
            ->groupBy('u.id', 'u.name')
            ->selectRaw('u.name, COUNT(DISTINCT pa.exam_session_id) sittings, SUM(pa.max_candidates) capacity,
                (SELECT COUNT(*) FROM proctor_candidate pc WHERE pc.proctor_assignment_id IN (SELECT id FROM proctor_assignments WHERE user_id = u.id)) candidates,
                (SELECT COUNT(*) FROM incident_notes n WHERE n.user_id = u.id) notes,
                (SELECT COUNT(*) FROM candidate_messages m WHERE m.sender_id = u.id) messages')
            ->orderBy('u.name')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Proctor', 'Sittings', 'Capacity', 'Candidates assigned', 'Incident notes', 'Messages / warnings'], $rows];
    }

    private function rCoursePerformance(array $f, int $limit): array
    {
        $pass = $this->passGrades();
        $rows = $this->offerings($f)->join('course_grades as g', 'g.course_offering_id', '=', 'o.id')
            ->groupBy('o.id', 'c.code', 'c.title', 's.name')
            ->selectRaw('c.code, c.title, s.name as semester, COUNT(*) students, ROUND(AVG(g.total),1) mean, MIN(g.total) lowest, MAX(g.total) highest,
                SUM(CASE WHEN g.grade IN ('.$pass.') THEN 1 ELSE 0 END) passed')
            ->orderBy('c.code')->limit($limit)->get()
            ->map(fn ($r) => (array) $r + ['pass_rate' => $this->pct($r->passed, $r->students), 'fail_rate' => $this->pct($r->students - $r->passed, $r->students)])->all();

        return [['Code', 'Title', 'Semester', 'Students', 'Mean', 'Lowest', 'Highest', 'Passed', 'Pass %', 'Fail %'], $rows];
    }

    private function rDepartmentPerformance(array $f, int $limit): array
    {
        $pass = $this->passGrades();
        $rows = $this->offerings($f)->join('course_grades as g', 'g.course_offering_id', '=', 'o.id')->leftJoin('faculties as fa', 'fa.id', '=', 'd.faculty_id')
            ->groupBy('fa.name', 'd.name')
            ->selectRaw('fa.name as faculty, d.name as department, COUNT(DISTINCT o.id) courses, COUNT(*) results, ROUND(AVG(g.total),1) mean,
                SUM(CASE WHEN g.grade IN ('.$pass.') THEN 1 ELSE 0 END) passed')
            ->orderBy('fa.name')->orderBy('d.name')->limit($limit)->get()
            ->map(fn ($r) => (array) $r + ['pass_rate' => $this->pct($r->passed, $r->results)])->all();

        return [['Faculty', 'Department', 'Courses', 'Results', 'Mean', 'Passed', 'Pass %'], $rows];
    }

    private function rResultChanges(array $f, int $limit): array
    {
        $rows = DB::table('result_changes as rc')->join('results as r', 'r.id', '=', 'rc.result_id')->join('exams as e', 'e.id', '=', 'r.exam_id')
            ->join('users as u', 'u.id', '=', 'r.user_id')->leftJoin('users as req', 'req.id', '=', 'rc.requested_by')->leftJoin('users as dec', 'dec.id', '=', 'rc.decided_by')
            ->where('r.tenant_id', tenant_id())
            ->selectRaw('rc.created_at, e.title as exam, u.identifier, rc.from_score, rc.to_score, rc.reason, req.name as requested_by, rc.status, dec.name as decided_by, rc.decided_at')
            ->orderByDesc('rc.created_at')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Requested', 'Exam', 'Candidate', 'Old', 'New', 'Reason', 'Requested by', 'Status', 'Decided by', 'Decided'], $rows];
    }

    private function rLecturerActivity(array $f, int $limit): array
    {
        $since = $f['from'] ?? now()->subDays(30)->toDateString();
        $rows = $this->offerings($f)->join('course_offering_staff as st', 'st.course_offering_id', '=', 'o.id')->join('users as u', 'u.id', '=', 'st.user_id')
            ->groupBy('u.id', 'u.name')
            ->selectRaw('u.name, COUNT(DISTINCT o.id) courses,
                (SELECT COUNT(*) FROM course_items ci WHERE ci.created_by = u.id AND ci.created_at >= ?) items_added,
                (SELECT COUNT(*) FROM assignment_submissions sub WHERE sub.marked_by = u.id AND sub.marked_at >= ?) marked,
                (SELECT COUNT(*) FROM forum_posts fp WHERE fp.user_id = u.id AND fp.created_at >= ?) forum_posts,
                (SELECT MAX(created_at) FROM security_events se WHERE se.user_id = u.id AND se.type = ?) last_login', [$since, $since, $since, 'login'])
            ->orderBy('u.name')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Lecturer', 'Courses', 'Materials added', 'Submissions marked', 'Forum posts', 'Last sign-in'], $rows];
    }

    private function rStaffWorkload(array $f, int $limit): array
    {
        $rows = $this->offerings($f)->join('course_offering_staff as st', 'st.course_offering_id', '=', 'o.id')->join('users as u', 'u.id', '=', 'st.user_id')
            ->groupBy('u.id', 'u.name')
            ->selectRaw("u.name, COUNT(DISTINCT o.id) courses, SUM(c.credit_units) units,
                SUM(CASE WHEN st.role = 'coordinator' THEN 1 ELSE 0 END) coordinating,
                (SELECT COUNT(*) FROM enrolments en WHERE en.course_offering_id IN (SELECT course_offering_id FROM course_offering_staff WHERE user_id = u.id) AND en.status = 'active') students,
                (SELECT COUNT(*) FROM assignment_submissions sub JOIN course_items ci ON ci.id = sub.course_item_id
                    WHERE ci.course_offering_id IN (SELECT course_offering_id FROM course_offering_staff WHERE user_id = u.id) AND sub.status = 'submitted') to_mark")
            ->orderByDesc('units')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Staff', 'Courses', 'Credit units', 'Coordinating', 'Students', 'Waiting to be marked'], $rows];
    }

    private function rCourseCoverage(array $f, int $limit): array
    {
        $rows = $this->offerings($f)->leftJoin('course_items as ci', fn ($j) => $j->on('ci.course_offering_id', '=', 'o.id')->whereNull('ci.deleted_at'))
            ->groupBy('o.id', 'c.code', 'c.title', 'o.review_status')
            ->selectRaw("c.code, c.title, o.review_status,
                (SELECT COUNT(*) FROM course_sections cs WHERE cs.course_offering_id = o.id) sections,
                COUNT(ci.id) items,
                SUM(CASE WHEN ci.id IS NOT NULL AND (ci.release_at IS NULL OR ci.release_at <= ?) THEN 1 ELSE 0 END) released,
                SUM(CASE WHEN ci.type = 'assignment' THEN 1 ELSE 0 END) assignments,
                SUM(CASE WHEN ci.type = 'quiz' THEN 1 ELSE 0 END) quizzes,
                SUM(CASE WHEN ci.type IN ('assignment','quiz') AND ci.due_at < ? THEN 1 ELSE 0 END) assessments_due", [now(), now()])
            ->orderBy('c.code')->limit($limit)->get()->map(fn ($r) => (array) $r + ['released_pct' => $this->pct($r->released, $r->items)])->all();

        return [['Code', 'Title', 'Review', 'Sections', 'Items', 'Released', 'Assignments', 'Quizzes', 'Assessments past due', 'Released %'], $rows];
    }

    private function rStudentEngagement(array $f, int $limit): array
    {
        $rows = $this->offerings($f)->join('enrolments as en', 'en.course_offering_id', '=', 'o.id')->join('users as u', 'u.id', '=', 'en.user_id')
            ->where('en.status', 'active')
            ->selectRaw("c.code, u.identifier, u.name,
                (SELECT COUNT(*) FROM item_completions ic JOIN course_items ci ON ci.id = ic.course_item_id WHERE ci.course_offering_id = o.id AND ic.user_id = u.id) completed,
                (SELECT COUNT(*) FROM course_items ci WHERE ci.course_offering_id = o.id AND ci.requires_completion = 1 AND ci.visible = 1 AND ci.deleted_at IS NULL) required,
                (SELECT COUNT(*) FROM assignment_submissions s JOIN course_items ci ON ci.id = s.course_item_id WHERE ci.course_offering_id = o.id AND s.user_id = u.id AND s.status <> 'draft') submissions,
                (SELECT COUNT(*) FROM forum_posts fp JOIN forum_threads ft ON ft.id = fp.forum_thread_id JOIN course_items ci ON ci.id = ft.course_item_id WHERE ci.course_offering_id = o.id AND fp.user_id = u.id) posts,
                (SELECT MAX(ic.completed_at) FROM item_completions ic JOIN course_items ci ON ci.id = ic.course_item_id WHERE ci.course_offering_id = o.id AND ic.user_id = u.id) last_activity")
            ->orderBy('c.code')->orderBy('u.name')->limit($limit)->get()
            ->map(fn ($r) => (array) $r + ['progress_pct' => $this->pct($r->completed, $r->required)])->all();

        return [['Course', 'Matric no.', 'Name', 'Completed', 'Required', 'Submissions', 'Forum posts', 'Last activity', 'Progress %'], $rows];
    }

    private function rCompliance(array $f, int $limit): array
    {
        $tid = tenant_id();
        $since = $f['from'] ?? now()->subDays(30)->toDateString();
        $broken = app(AuditLogger::class)->verify($tid);
        $required = (array) Policy::get('mfa_required_roles');
        $privileged = $required ? DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('roles.tenant_id', $tid)->whereIn('roles.name', $required)->distinct()->pluck('model_id') : collect();
        $rows = [
            ['Audit chain', $broken ? "BROKEN at entry #$broken" : 'Intact', 'php artisan audit:verify'],
            ['Audit entries since '.$since, DB::table('audit_logs')->where('tenant_id', $tid)->where('created_at', '>=', $since)->count(), ''],
            ['Privileged actions (roles, policy, people)', DB::table('audit_logs')->where('tenant_id', $tid)->where('created_at', '>=', $since)->where(fn ($q) => $q->where('action', 'like', 'people.%')->orWhere('action', 'like', 'policy.%')->orWhere('action', 'like', 'module%'))->count(), ''],
            ['Evidence viewed or downloaded', DB::table('evidence_access_logs')->where('created_at', '>=', $since)->count(), 'every access is logged'],
            ['Evidence past retention still stored', DB::table('evidence_files')->where('tenant_id', $tid)->whereNotNull('delete_after')->where('delete_after', '<', now())->count(), 'should be 0; evidence:purge runs daily'],
            ['Failed sign-ins', DB::table('security_events')->where('tenant_id', $tid)->where('type', 'login_failed')->where('created_at', '>=', $since)->count(), ''],
            ['Locked-out sign-ins', DB::table('security_events')->where('tenant_id', $tid)->where('type', 'login_locked')->where('created_at', '>=', $since)->count(), ''],
            ['Rejected exam tokens / API keys', DB::table('security_events')->where('tenant_id', $tid)->whereIn('type', ['token_rejected', 'api_key_rejected'])->where('created_at', '>=', $since)->count(), ''],
            ['Privileged users without two-factor', $privileged->isEmpty() ? 0 : DB::table('users')->whereIn('id', $privileged)->whereNull('two_factor_confirmed_at')->count(), 'should be 0'],
            ['Personal-data exports approved', DB::table('export_requests')->where('tenant_id', $tid)->whereIn('status', ['approved', 'used'])->where('created_at', '>=', $since)->count(), ''],
            ['Last successful backup', DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->max('created_at') ?? 'never', ''],
            ['Last restore test', ($t = DB::table('backup_runs')->where('kind', 'restore_test')->latest('id')->first()) ? "{$t->status} {$t->created_at}" : 'never', ''],
            ['Last access review completed', DB::table('access_reviews')->where('tenant_id', $tid)->whereNotNull('completed_at')->max('completed_at') ?? 'never', 'every '.Policy::get('access_review_days').' days'],
        ];

        return [['Check', 'Value', 'Note'], array_map(fn ($r) => array_combine(['check', 'value', 'note'], $r), $rows)];
    }

    private function rAccessReview(array $f, int $limit): array
    {
        $rows = DB::table('model_has_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('r.tenant_id', tenant_id())->whereNotIn('r.name', ['student'])
            ->selectRaw("u.name, u.email, r.name as role, u.status, CASE WHEN u.two_factor_confirmed_at IS NULL THEN 'no' ELSE 'yes' END two_factor,
                (SELECT MAX(created_at) FROM security_events se WHERE se.user_id = u.id AND se.type = 'login') last_login,
                (SELECT MAX(expires_at) FROM role_grants g WHERE g.user_id = u.id AND g.role = r.name AND g.revoked_at IS NULL) temporary_until")
            ->orderBy('r.name')->orderBy('u.name')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();

        return [['Name', 'Email', 'Role', 'Account', 'Two-factor', 'Last sign-in', 'Temporary until'], $rows];
    }

    private function passGrades(): string
    {
        $scale = Policy::get('grading_scale');
        $min = $scale[Policy::get('pass_grade_min')] ?? 40;

        return collect($scale)->filter(fn ($v) => $v >= $min)->keys()->map(fn ($g) => DB::getPdo()->quote((string) $g))->implode(',') ?: "'A'";
    }
}
