<?php

namespace App\Services\Monitoring;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Integrations\Connectors;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Exam-hall network monitoring (Target 162): the switches, access points, routers and the
 * internet link each hall depends on are checked every minute with a short TCP connection
 * (no special privileges needed). Three failures in a row mark a device down and tell the
 * institution's ICT and exams officers; slow answers are shown as "slow".
 */
class NetworkMonitor
{
    public const PORTS = [22, 23, 53, 80, 161, 443, 8080, 8443];

    public const SLOW_MS = 300;

    public function probeAll(): int
    {
        $n = 0;
        foreach (Tenant::where('status', 'active')->get() as $t) {
            app(TenantContext::class)->run($t, function () use ($t, &$n) {
                foreach (DB::table('network_devices')->where('tenant_id', $t->id)->get() as $d) {
                    $this->probe($d, $t);
                    $n++;
                }
            });
        }
        DB::table('network_checks')->where('checked_at', '<', now()->subDays(14))->delete();

        return $n;
    }

    public function probe(object $d, Tenant $t): array
    {
        $ms = null;
        $ok = false;
        if (Connectors::safeHost($d->host) && in_array((int) $d->port, self::PORTS, true)) {
            $ms = $this->reach($d->host, (int) $d->port);
            $ok = $ms !== null;
        }
        $fails = $ok ? 0 : $d->fail_count + 1;
        $status = $ok ? ($ms > self::SLOW_MS ? 'slow' : 'up') : ($fails >= 3 ? 'down' : $d->status);
        DB::table('network_checks')->insert(['network_device_id' => $d->id, 'ok' => $ok, 'latency_ms' => $ms, 'checked_at' => now()]);
        DB::table('network_devices')->where('id', $d->id)->update(['status' => $status, 'latency_ms' => $ms, 'fail_count' => $fails,
            'last_checked_at' => now(), 'last_up_at' => $ok ? now() : $d->last_up_at]);
        if ($status === 'down' && $d->status !== 'down') {
            $this->tell("{$d->name} is not answering", "{$d->name} ({$d->host}) stopped answering at ".now()->format('H:i').'. Candidates in that hall may lose connection; answers are kept and time can be restored.');
        } elseif ($d->status === 'down' && $ok) {
            $this->tell("{$d->name} is back", "{$d->name} ({$d->host}) is answering again.");
        }

        return ['ok' => $ok, 'ms' => $ms, 'status' => $status];
    }

    /** Milliseconds to open a TCP connection, or null when nothing answers. */
    protected function reach(string $host, int $port): ?int
    {
        $start = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $err, 1.5);
        if (! $fp) {
            return null;
        }
        fclose($fp);

        return (int) round((microtime(true) - $start) * 1000);
    }

    private function tell(string $title, string $body): void
    {
        Notification::send(User::permission(['institution.manage', 'exams.manage'])->where('status', 'active')->get(), new LmsNotice($title, $body, '/admin/system-health?tab=network', true, category: 'system'));
    }
}
