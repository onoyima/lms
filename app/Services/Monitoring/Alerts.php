<?php

namespace App\Services\Monitoring;

use App\Models\User;
use App\Notifications\LmsNotice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Automated alerts for service failures, slowness and storage (LMS 245). An alert opens when a
 * threshold is crossed for the stated number of minutes and closes when it recovers; both are
 * sent to platform administrators (in-app and e-mail) and, if set, to an alert webhook
 * (Slack/Teams/Google Chat style {"text": "..."}).
 */
class Alerts
{
    /** key => [label, metric, comparison, warning, critical, minutes] */
    public const RULES = [
        'cpu' => ['CPU busy', 'cpu_pct', '>', 85, 95, 5],
        'memory' => ['Memory nearly full', 'memory_pct', '>', 90, 97, 5],
        'disk' => ['Disk space low', 'disk_free_pct', '<', 15, 7, 1],
        'errors' => ['Server errors', 'error_pct', '>', 2, 5, 3],
        'slow' => ['Slow responses (95th percentile)', 'p95_ms', '>', 1000, 2500, 5],
        'queue' => ['Background queue backing up', 'queue_waiting', '>', 500, 2000, 5],
        'db' => ['Many database connections', 'db_connections', '>', 300, 450, 5],
    ];

    public function evaluate(): array
    {
        $rows = DB::table('system_metrics')->where('minute', '>=', now()->subMinutes(15))->orderByDesc('minute')->get()
            ->map(fn ($r) => json_decode($r->data, true));
        $opened = [];
        foreach (self::RULES as $key => [$label, $metric, $cmp, $warn, $crit, $minutes]) {
            $recent = $rows->take($minutes)->pluck($metric)->filter(fn ($v) => $v !== null);
            if ($key === 'errors') {
                // a few errors on a quiet server are not an outage
                $recent = $rows->take($minutes)->filter(fn ($r) => ($r['requests'] ?? 0) >= 50)->pluck($metric);
            }
            $over = fn ($t) => $recent->count() >= $minutes && $recent->every(fn ($v) => $cmp === '>' ? $v > $t : $v < $t);
            $level = $over($crit) ? 'critical' : ($over($warn) ? 'warning' : null);
            $value = $recent->first();
            if ($this->state($key, $level, $label.': '.$value.($metric === 'p95_ms' ? ' ms' : (str_ends_with($metric, '_pct') ? '%' : '')), $value)) {
                $opened[] = $key;
            }
        }
        // services
        $beat = Cache::get('health:scheduler');
        $this->state('scheduler', $beat && now()->getTimestamp() - (int) $beat < 300 ? null : 'critical', 'The scheduler has stopped (auto-submit, backups and billing are not running).');
        $backup = DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->max('created_at');
        $this->state('backup', $backup && now()->diffInHours($backup, true) <= 26 ? null : 'critical', 'No successful backup in the last 26 hours.');
        $down = DB::table('network_devices')->where('status', 'down')->count();
        $this->state('network', $down ? 'warning' : null, "$down exam-hall network device(s) not answering.", $down);

        return $opened;
    }

    /** Open, update or close one alert. Returns true when an alert was newly opened. */
    private function state(string $key, ?string $level, string $message, $value = null): bool
    {
        $open = DB::table('system_alerts')->where('key', $key)->whereNull('resolved_at')->first();
        if ($level && ! $open) {
            DB::table('system_alerts')->insert(['key' => $key, 'level' => $level, 'message' => $message, 'value' => is_numeric($value) ? $value : null, 'opened_at' => now()]);
            $this->send(strtoupper($level).': '.$message);

            return true;
        }
        if ($level && $open && $open->level !== $level) {
            DB::table('system_alerts')->where('id', $open->id)->update(['level' => $level, 'message' => $message]);
            if ($level === 'critical') {
                $this->send('CRITICAL: '.$message);
            }
        }
        if (! $level && $open) {
            DB::table('system_alerts')->where('id', $open->id)->update(['resolved_at' => now()]);
            $this->send('Resolved: '.$open->message);
        }

        return false;
    }

    private function send(string $text): void
    {
        $admins = User::withoutGlobalScopes()->whereNull('tenant_id')->where('is_platform_admin', true)->where('status', 'active')->get();
        Notification::send($admins, new LmsNotice('Platform alert', $text, '/platform/health', true, category: 'system'));
        if ($url = (string) config('platform.alert_webhook')) {
            try {
                Http::timeout(10)->post($url, ['text' => '['.\App\Support\Platform\PlatformSettings::brand()['name'].'] '.$text]);
            } catch (\Throwable) {
            }
        }
    }
}
