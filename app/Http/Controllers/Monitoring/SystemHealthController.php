<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\Connectors;
use App\Services\Monitoring\Alerts;
use App\Services\Monitoring\NetworkMonitor;
use App\Services\Monitoring\RecoveryDrill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance, alerts, recovery drills (platform) and exam-hall network (institution).
 * Server figures are about the whole service and contain no institution's records.
 */
class SystemHealthController extends Controller
{
    public const RANGES = ['1h' => [60, 1], '24h' => [1440, 10], '7d' => [10080, 60]];

    public function __construct(private AuditLogger $audit) {}

    /** Time series, averaged into buckets so a week stays light. */
    public static function series(string $range): array
    {
        [$minutes, $step] = self::RANGES[$range] ?? self::RANGES['1h'];
        $rows = DB::table('system_metrics')->where('minute', '>=', now()->subMinutes($minutes))->orderBy('minute')->get();
        $out = [];
        foreach ($rows->groupBy(fn ($r) => intdiv(strtotime($r->minute), $step * 60)) as $group) {
            $data = $group->map(fn ($r) => json_decode($r->data, true));
            $point = ['t' => $group->first()->minute];
            foreach (['requests', 'errors', 'exam_requests', 'answers_saved', 'queue_waiting', 'db_slow_queries'] as $sum) {
                $point[$sum] = $data->sum($sum);
            }
            foreach (['error_pct', 'cpu_pct', 'memory_pct', 'disk_free_pct', 'db_connections', 'candidates_writing'] as $avg) {
                $v = $data->pluck($avg)->filter(fn ($x) => $x !== null);
                $point[$avg] = $v->isEmpty() ? null : round($v->avg(), 1);
            }
            $point['p95_ms'] = $data->pluck('p95_ms')->filter()->max();
            $out[] = $point;
        }

        return $out;
    }

    private function now(): array
    {
        $last = DB::table('system_metrics')->latest('minute')->first();

        return $last ? json_decode($last->data, true) + ['at' => $last->minute] : [];
    }

    public function platform(Request $request): Response
    {
        $range = (string) $request->query('range', '1h');

        return Inertia::render('platform/health', [
            'range' => $range, 'series' => self::series($range), 'current' => $this->now(),
            'alerts' => DB::table('system_alerts')->orderByRaw('resolved_at IS NULL DESC')->orderByDesc('opened_at')->limit(50)->get(),
            'rules' => Alerts::RULES,
            'drills' => DB::table('dr_drills')->orderByDesc('id')->limit(12)->get()->map(fn ($d) => array_merge((array) $d, ['steps' => json_decode($d->steps ?? '[]', true) ?: []])),
            'webhook' => (bool) config('platform.alert_webhook'),
        ]);
    }

    public function acknowledge(Request $request, int $id): RedirectResponse
    {
        DB::table('system_alerts')->where('id', $id)->whereNull('acknowledged_at')->update(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id]);

        return back();
    }

    public function drill(Request $request, RecoveryDrill $drill): RedirectResponse
    {
        abort_if(DB::table('dr_drills')->where('status', 'running')->where('started_at', '>', now()->subHour())->exists(), 409, 'A drill is already running.');
        $userId = $request->user()->id;
        dispatch(fn () => $drill->run($userId))->afterResponse();
        $this->audit->record('platform.dr_drill_started', null);

        return back()->with('success', 'Recovery drill started. Refresh in a minute or two to see the result.');
    }

    /* ---------- institution: exam-day performance and hall network ---------- */

    public function institution(Request $request): Response
    {
        $range = (string) $request->query('range', '1h');
        $devices = DB::table('network_devices')->where('tenant_id', tenant_id())->orderBy('venue_id')->orderBy('name')->get();
        $checks = DB::table('network_checks')->whereIn('network_device_id', $devices->pluck('id'))->where('checked_at', '>', now()->subHour())->orderBy('checked_at')->get()->groupBy('network_device_id');

        return Inertia::render('admin/system-health', [
            'range' => $range, 'series' => self::series($range), 'current' => $this->now(),
            'devices' => $devices->map(fn ($d) => array_merge((array) $d, ['history' => ($checks[$d->id] ?? collect())->map(fn ($c) => $c->ok ? (int) $c->latency_ms : null)->values()])),
            'venues' => Venue::orderBy('name')->get(['id', 'name', 'campus']),
            'kinds' => ['switch', 'access_point', 'router', 'server', 'internet', 'printer', 'other'],
            'ports' => NetworkMonitor::PORTS,
            'tab' => $request->query('tab', 'performance'),
            'computers' => DB::table('devices')->where('tenant_id', tenant_id())->where('status', 'approved')
                ->selectRaw('venue_id, COUNT(*) as total, SUM(last_seen_at > ?) as online', [now()->subMinutes(5)])->groupBy('venue_id')->get(),
        ]);
    }

    public function storeDevice(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'kind' => ['required', 'in:switch,access_point,router,server,internet,printer,other'],
            'host' => ['required', 'string', 'max:190'], 'port' => ['required', 'integer', 'in:'.implode(',', NetworkMonitor::PORTS)], 'venue_id' => ['nullable', 'integer']]);
        abort_unless(Connectors::safeHost($data['host']), 422, 'That address is not allowed (loopback, link-local or, on shared hosting, private addresses).');
        if ($data['venue_id'] ?? null) {
            abort_unless(Venue::whereKey($data['venue_id'])->exists(), 422);
        }
        $id = DB::table('network_devices')->insertGetId($data + ['tenant_id' => tenant_id(), 'status' => 'unknown', 'created_at' => now(), 'updated_at' => now()]);
        app(NetworkMonitor::class)->probe(DB::table('network_devices')->find($id), tenant());
        $this->audit->record('network.device_added', null, ['host' => $data['host']]);

        return back()->with('success', 'Added and checked.');
    }

    public function deleteDevice(int $id): RedirectResponse
    {
        abort_unless(DB::table('network_devices')->where('tenant_id', tenant_id())->where('id', $id)->delete(), 404);

        return back()->with('success', 'Removed.');
    }

    public function probeNow(): RedirectResponse
    {
        foreach (DB::table('network_devices')->where('tenant_id', tenant_id())->get() as $d) {
            app(NetworkMonitor::class)->probe($d, tenant());
        }

        return back()->with('success', 'Checked every device.');
    }
}
