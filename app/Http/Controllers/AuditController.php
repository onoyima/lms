<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Search and export the tamper-evident audit trail. An institution sees only its own chain; the platform
 * console sees its own chain and, for support and investigations, any one institution's.
 */
class AuditController extends Controller
{
    public function __construct(private AuditLogger $audit, private TenantContext $context) {}

    private function filtered(Request $request, ?int $tenantId): Builder
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'actor' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'tenant' => ['nullable', 'integer']]);

        return AuditLog::query()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('action', 'like', '%'.addcslashes($s, '%_\\').'%')->orWhere('data', 'like', '%'.addcslashes($s, '%_\\').'%')))
            ->when($f['actor'] ?? null, fn ($q, $s) => $q->whereIn('actor_id', User::withoutGlobalScopes()->where(fn ($w) => $w->where('tenant_id', $tenantId)->orWhere('is_platform_admin', true))
                ->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($s, '%_\\').'%')->orWhere('email', 'like', '%'.addcslashes($s, '%_\\').'%'))->pluck('id')))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', \Carbon\Carbon::parse($d)->addDay()))
            ->orderByDesc('id');
    }

    private function row(AuditLog $l, array $names): array
    {
        return ['id' => $l->id, 'action' => $l->action, 'actor' => $names[$l->actor_id] ?? ($l->actor_id ? "#{$l->actor_id}" : 'System'), 'subject' => $l->subject_type ? class_basename($l->subject_type).' #'.$l->subject_id : null,
            'data' => $l->data, 'ip' => $l->ip, 'at' => $l->created_at?->toIso8601String()];
    }

    private function names($logs): array
    {
        return User::withoutGlobalScopes()->whereIn('id', $logs->pluck('actor_id')->filter()->unique())->pluck('name', 'id')->all();
    }

    private function page(Request $request, ?int $tenantId, string $view, array $extra = []): Response
    {
        $logs = $this->filtered($request, $tenantId)->paginate(50)->withQueryString();
        $names = $this->names($logs->getCollection());

        return Inertia::render($view, $extra + [
            'logs' => $logs->through(fn ($l) => $this->row($l, $names)),
            'filters' => $request->only('q', 'actor', 'from', 'to', 'tenant'),
            'broken' => $request->boolean('verify') ? ($this->audit->verify($tenantId) ?? 0) : null,
        ]);
    }

    private function export(Request $request, ?int $tenantId, string $label): StreamedResponse
    {
        $query = $this->filtered($request, $tenantId);
        $this->audit->record('audit.exported', null, ['filters' => $request->only('q', 'actor', 'from', 'to'), 'scope' => $label]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'when', 'actor', 'action', 'subject', 'details', 'ip', 'hash']);
            $query->chunk(1000, function ($logs) use ($out) {
                $names = $this->names($logs);
                foreach ($logs as $l) {
                    $r = $this->row($l, $names);
                    // spreadsheet formula injection: never start a cell with = + - @
                    $cells = array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, [$r['id'], $r['at'], $r['actor'], $r['action'], $r['subject'], json_encode($r['data']), $r['ip'], $l->hash]);
                    fputcsv($out, $cells);
                }
            });
            fclose($out);
        }, 'audit-'.$label.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request): Response
    {
        return $this->page($request, $this->context->id(), 'admin/audit');
    }

    public function download(Request $request): StreamedResponse
    {
        return $this->export($request, $this->context->id(), (string) $this->context->get()?->slug);
    }

    public function platform(Request $request): Response
    {
        $tid = $request->integer('tenant') ?: null;
        abort_if($tid && ! Tenant::whereKey($tid)->exists(), 404);
        if ($tid && $request->query('page') === null && ! $request->boolean('verify')) {
            // recorded in the institution's own trail, so it can see who looked
            $this->context->run(Tenant::find($tid), fn () => $this->audit->record('platform.audit_viewed', null, ['platform_user' => $request->user()->name]));
        }

        return $this->page($request, $tid, 'platform/audit', ['institutions' => Tenant::orderBy('name')->get(['id', 'name'])]);
    }

    public function platformDownload(Request $request): StreamedResponse
    {
        $tid = $request->integer('tenant') ?: null;

        return $this->export($request, $tid, $tid ? (string) Tenant::findOrFail($tid)->slug : 'platform');
    }
}
