<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Built-in monitoring (LMS 140, 143, 243, 245; Target 101, 185). Requests are counted per minute in
 * the cache (a handful of increments, after the response). Once a minute the scheduler adds server
 * figures — CPU, memory, disk, database connections, queue, candidates writing — and stores one row.
 * Alerts open and close against thresholds and are sent to platform administrators.
 */
class Metrics
{
    /** response-time buckets in ms; p95 is read from these */
    public const BUCKETS = [100, 250, 500, 1000, 2500, 5000];

    public static function request(int $status, float $ms, bool $exam): void
    {
        try {
            $m = 'metrics:'.date('YmdHi').':';
            $ttl = now()->addMinutes(10);
            Cache::add($m.'req', 0, $ttl);
            Cache::increment($m.'req');
            if ($status >= 500) {
                Cache::add($m.'err', 0, $ttl);
                Cache::increment($m.'err');
            }
            if ($exam) {
                Cache::add($m.'exam', 0, $ttl);
                Cache::increment($m.'exam');
            }
            $bucket = 'inf';
            foreach (self::BUCKETS as $b) {
                if ($ms <= $b) {
                    $bucket = $b;
                    break;
                }
            }
            Cache::add($m.'b'.$bucket, 0, $ttl);
            Cache::increment($m.'b'.$bucket);
        } catch (\Throwable) {
            // monitoring must never break a request
        }
    }

    /** Figures for one finished minute. */
    public function collect(?\DateTimeInterface $minute = null): array
    {
        $minute ??= now()->subMinute()->startOfMinute();
        $m = 'metrics:'.$minute->format('YmdHi').':';
        $req = (int) Cache::get($m.'req', 0);
        $buckets = [];
        foreach ([...self::BUCKETS, 'inf'] as $b) {
            $buckets[$b] = (int) Cache::get($m.'b'.$b, 0);
        }
        $p95 = null;
        if ($req > 0) {
            $need = (int) ceil($req * 0.95);
            $seen = 0;
            foreach ($buckets as $b => $n) {
                $seen += $n;
                if ($seen >= $need) {
                    $p95 = $b === 'inf' ? 10000 : (int) $b;
                    break;
                }
            }
        }
        $free = @disk_free_space(storage_path()) ?: 0;
        $total = @disk_total_space(storage_path()) ?: 1;

        return array_filter([
            'requests' => $req,
            'errors' => (int) Cache::get($m.'err', 0),
            'error_pct' => $req ? round(Cache::get($m.'err', 0) / $req * 100, 2) : 0,
            'exam_requests' => (int) Cache::get($m.'exam', 0),
            'p95_ms' => $p95,
            'cpu_pct' => $this->cpu(),
            'memory_pct' => $this->memory(),
            'disk_free_pct' => round($free / $total * 100, 1),
            'db_connections' => $this->dbStatus('Threads_connected'),
            'db_slow_queries' => $this->delta('Slow_queries'),
            'queue_waiting' => DB::table('jobs')->count(),
            'failed_jobs_1h' => DB::table('failed_jobs')->where('failed_at', '>', now()->subHour())->count(),
            'candidates_writing' => DB::table('attempts')->whereIn('status', ['in_progress', 'paused'])->where('last_seen_at', '>', now()->subMinutes(2))->count(),
            'answers_saved' => DB::table('attempt_answers')->where('answered_at', '>=', $minute)->where('answered_at', '<', (clone \Carbon\Carbon::instance($minute))->addMinute())->count(),
        ], fn ($v) => $v !== null);
    }

    public function store(): array
    {
        $minute = now()->subMinute()->startOfMinute();
        $data = $this->collect($minute);
        DB::table('system_metrics')->updateOrInsert(['minute' => $minute], ['data' => json_encode($data)]);
        DB::table('system_metrics')->where('minute', '<', now()->subDays(30))->delete();

        return $data;
    }

    private function cpu(): ?float
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/stat')) {
            $read = fn () => array_map('intval', array_slice(preg_split('/\s+/', trim(strtok(file_get_contents('/proc/stat'), "\n"))), 1, 8));
            $a = $read();
            usleep(250000);
            $b = $read();
            $idle = ($b[3] + $b[4]) - ($a[3] + $a[4]);
            $all = array_sum($b) - array_sum($a);

            return $all > 0 ? round((1 - $idle / $all) * 100, 1) : null;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $out = @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average"');

            return is_numeric(trim((string) $out)) ? (float) trim($out) : null;
        }
        if (function_exists('sys_getloadavg') && ($l = sys_getloadavg())) {
            return round(min(100, $l[0] * 100 / max(1, (int) @shell_exec('sysctl -n hw.ncpu') ?: 1)), 1);
        }

        return null;
    }

    private function memory(): ?float
    {
        if (is_readable('/proc/meminfo')) {
            preg_match_all('/^(MemTotal|MemAvailable):\s+(\d+)/m', file_get_contents('/proc/meminfo'), $m);
            $v = array_combine($m[1], array_map('intval', $m[2]));

            return isset($v['MemTotal'], $v['MemAvailable']) ? round((1 - $v['MemAvailable'] / $v['MemTotal']) * 100, 1) : null;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $out = (string) @shell_exec('powershell -NoProfile -Command "$o=Get-CimInstance Win32_OperatingSystem; [math]::Round((1-$o.FreePhysicalMemory/$o.TotalVisibleMemorySize)*100,1)"');

            return is_numeric(trim($out)) ? (float) trim($out) : null;
        }

        return null;
    }

    /** A server counter that only grows: report the increase since the last minute. */
    private function delta(string $name): ?int
    {
        $now = $this->dbStatus($name);
        if ($now === null) {
            return null;
        }
        $prev = Cache::get('metrics:prev:'.$name);
        Cache::put('metrics:prev:'.$name, $now, now()->addHour());

        return $prev === null ? 0 : max(0, $now - (int) $prev);
    }

    private function dbStatus(string $name): ?int
    {
        try {
            if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                return null;
            }
            $row = preg_match('/^\w+$/', $name) ? DB::selectOne("SHOW GLOBAL STATUS LIKE '$name'") : null; // names are fixed in this class

            return $row ? (int) $row->Value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
