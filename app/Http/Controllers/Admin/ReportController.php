<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Reports\Exporter;
use App\Services\Reports\ReportCatalog;
use App\Support\Policy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reports screen, exports, approval of large personal-data exports (LMS 196-197, 213)
 * and periodic access reviews (LMS 214; Target 219).
 */
class ReportController extends Controller
{
    public function __construct(private ReportCatalog $catalog, private Exporter $exporter, private AuditLogger $audit) {}

    private function filters(Request $request): array
    {
        return $request->validate([
            'session_id' => ['nullable', 'integer'], 'semester_id' => ['nullable', 'integer'], 'faculty_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'], 'course_id' => ['nullable', 'integer'], 'level' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'only_absent' => ['nullable', 'boolean'],
        ]);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $allowed = $this->catalog->allowed($user);
        abort_if(! $allowed, 403);
        $key = $request->query('report');
        $result = null;
        if ($key) {
            $f = $this->filters($request);
            $result = $this->catalog->run($key, $f, $user, 501);
            $result['truncated'] = count($result['rows']) > 500;
            $result['rows'] = array_slice($result['rows'], 0, 500);
        }

        return Inertia::render('admin/reports', [
            'reports' => collect($allowed)->map(fn ($d, $k) => ['key' => $k, 'title' => $d[0], 'group' => $d[1], 'personal' => $d[3]])->values(),
            'current' => $key, 'result' => $result, 'filters' => $request->except('report'),
            'options' => [
                'sessions' => DB::table('academic_sessions')->where('tenant_id', tenant_id())->orderByDesc('id')->get(['id', 'name']),
                'semesters' => DB::table('semesters')->join('academic_sessions', 'academic_sessions.id', '=', 'semesters.academic_session_id')->where('semesters.tenant_id', tenant_id())
                    ->orderByDesc('semesters.id')->get(['semesters.id', 'academic_sessions.name as session', 'semesters.name'])->map(fn ($s) => ['id' => $s->id, 'name' => "{$s->session} {$s->name}"]),
                'faculties' => DB::table('faculties')->where('tenant_id', tenant_id())->orderBy('name')->get(['id', 'name']),
                'departments' => DB::table('departments')->where('tenant_id', tenant_id())->orderBy('name')->get(['id', 'name', 'faculty_id']),
            ],
            'approvalRows' => (int) Policy::get('export_approval_rows'),
            'myRequests' => DB::table('export_requests')->where('user_id', $user->id)->latest('id')->limit(10)->get(),
            'pending' => $user->can('users.manage') || $user->can('audit.view')
                ? DB::table('export_requests')->join('users', 'users.id', '=', 'export_requests.user_id')->where('export_requests.tenant_id', tenant_id())
                    ->where('export_requests.status', 'pending')->where('export_requests.user_id', '!=', $user->id)->get(['export_requests.*', 'users.name']) : [],
        ]);
    }

    public function export(Request $request, string $report, string $format)
    {
        abort_unless(in_array($format, ['csv', 'xlsx', 'pdf'], true), 404);
        $f = $this->filters($request);
        $result = $this->catalog->run($report, $f, $request->user());
        $count = count($result['rows']);

        // Large exports of personal data need someone else's approval first
        if ($result['personal'] && $count > Policy::get('export_approval_rows')) {
            // filters are compared after decoding: MySQL stores JSON in its own form (key order, spacing), so a text comparison fails there
            $ok = DB::table('export_requests')->where('user_id', $request->user()->id)->where('report', $report)
                ->where('status', 'approved')->where('expires_at', '>', now())->orderByDesc('id')->get()
                ->first(fn ($r) => json_decode((string) $r->filters, true) == $f);
            if (! $ok) {
                return back()->withErrors(['export' => "This export has $count rows of personal data. Request approval first (limit ".Policy::get('export_approval_rows').').']);
            }
            DB::table('export_requests')->where('id', $ok->id)->update(['status' => 'used', 'updated_at' => now()]);
        }
        $this->audit->record('report.exported', null, ['report' => $report, 'format' => $format, 'rows' => $count, 'filters' => $f]);
        $name = $report.'-'.now()->format('Ymd-His');

        return match ($format) {
            'csv' => $this->exporter->csv($name, $result['columns'], $result['rows']),
            'xlsx' => $this->exporter->xlsx($name, $result['title'], $result['columns'], $result['rows']),
            'pdf' => $this->exporter->pdf($name, 'pdf.report', ['title' => $result['title'], 'columns' => $result['columns'], 'rows' => array_slice($result['rows'], 0, 3000),
                'truncated' => $count > 3000, 'subtitle' => $this->describe($f), 'footer' => 'Confidential — '.$request->user()->name], count($result['columns']) > 6 ? 'landscape' : 'portrait'),
        };
    }

    public function requestExport(Request $request, string $report, string $format): RedirectResponse
    {
        $f = $this->filters($request);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:300']])['reason'];
        $rows = count($this->catalog->run($report, $f, $request->user())['rows']);
        DB::table('export_requests')->insert(['tenant_id' => tenant_id(), 'user_id' => $request->user()->id, 'report' => $report, 'filters' => json_encode($f),
            'format' => $format, 'rows' => $rows, 'reason' => $reason, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Export requested. Another administrator must approve it.');
    }

    public function decideExport(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->can('users.manage') || $request->user()->can('audit.view'), 403);
        $req = DB::table('export_requests')->where('tenant_id', tenant_id())->where('id', $id)->where('status', 'pending')->first() ?? abort(404);
        abort_if($req->user_id === $request->user()->id, 403, 'You cannot approve your own export.');
        $approve = $request->boolean('approve');
        DB::table('export_requests')->where('id', $id)->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $request->user()->id,
            'decided_at' => now(), 'expires_at' => $approve ? now()->addDay() : null, 'updated_at' => now()]);
        $this->audit->record($approve ? 'export.approved' : 'export.rejected', User::find($req->user_id), ['request' => $id, 'report' => $req->report, 'rows' => $req->rows]);

        return back()->with('success', $approve ? 'Approved for 24 hours.' : 'Rejected.');
    }

    // ── Access reviews ───────────────────────────────────────────────────

    public function accessReview(Request $request): Response
    {
        abort_unless($request->user()->can('users.manage'), 403);
        $rows = $this->catalog->run('access_review', [], $request->user())['rows'];
        $open = DB::table('access_reviews')->where('tenant_id', tenant_id())->whereNull('completed_at')->latest('id')->first();

        return Inertia::render('admin/access-review', [
            'rows' => DB::table('model_has_roles as mr')->join('roles as r', 'r.id', '=', 'mr.role_id')->join('users as u', 'u.id', '=', 'mr.model_id')
                ->where('r.tenant_id', tenant_id())->where('r.name', '!=', 'student')->orderBy('u.name')
                ->get(['u.id as user_id', 'u.name', 'u.email', 'u.status', 'r.name as role', 'u.two_factor_confirmed_at'])
                ->map(fn ($r) => (array) $r + ['last_login' => collect($rows)->firstWhere('email', $r->email)['last_login'] ?? null]),
            'open' => $open ? ['id' => $open->id, 'decisions' => json_decode($open->decisions ?? '[]', true), 'created_at' => $open->created_at] : null,
            'last' => DB::table('access_reviews')->where('tenant_id', tenant_id())->whereNotNull('completed_at')->max('completed_at'),
            'everyDays' => Policy::get('access_review_days'),
        ]);
    }

    public function completeReview(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);
        $data = $request->validate(['decisions' => ['required', 'array'], 'decisions.*.user_id' => ['required', 'integer'], 'decisions.*.role' => ['required', 'string'],
            'decisions.*.keep' => ['required', 'boolean'], 'decisions.*.note' => ['nullable', 'string', 'max:300']]);
        $removed = 0;
        DB::transaction(function () use ($data, $request, &$removed) {
            foreach ($data['decisions'] as $d) {
                if (! $d['keep'] && $d['user_id'] !== $request->user()->id) {
                    $u = User::find($d['user_id']);
                    if ($u?->hasRole($d['role'])) {
                        $u->removeRole($d['role']);
                        $removed++;
                    }
                }
            }
            DB::table('access_reviews')->insert(['tenant_id' => tenant_id(), 'started_by' => $request->user()->id, 'decisions' => json_encode($data['decisions']),
                'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->audit->record('access_review.completed', null, ['reviewed' => count($data['decisions']), 'removed' => $removed]);

        return back()->with('success', 'Access review recorded. '.$removed.' role(s) removed.');
    }

    private function describe(array $f): string
    {
        $parts = [];
        foreach (['session_id' => 'academic_sessions', 'faculty_id' => 'faculties', 'department_id' => 'departments'] as $k => $t) {
            if (! empty($f[$k])) {
                $parts[] = DB::table($t)->where('id', $f[$k])->value('name');
            }
        }
        ! empty($f['level']) && $parts[] = $f['level'].' level';
        ! empty($f['from']) && $parts[] = 'from '.$f['from'];
        ! empty($f['to']) && $parts[] = 'to '.$f['to'];

        return implode(' · ', array_filter($parts)) ?: 'All records';
    }
}
