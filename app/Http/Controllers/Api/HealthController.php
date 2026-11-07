<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Health probe for monitoring tools and the exams office before a sitting.
 * Without the HEALTH_TOKEN it only answers ok/degraded (nothing internal is exposed).
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $checks = [];
        $t = microtime(true);
        try {
            DB::select('select 1');
            $checks['database'] = ['ok' => true, 'ms' => round((microtime(true) - $t) * 1000, 1)];
        } catch (\Throwable) {
            $checks['database'] = ['ok' => false];
        }
        try {
            Cache::put('health:probe', 1, 10);
            $checks['cache'] = ['ok' => Cache::get('health:probe') === 1, 'store' => config('cache.default')];
        } catch (\Throwable) {
            $checks['cache'] = ['ok' => false];
        }
        $beat = Cache::get('health:scheduler');
        $checks['scheduler'] = ['ok' => $beat && now()->getTimestamp() - $beat < 180, 'last' => $beat ? date(DATE_ATOM, $beat) : null];
        try {
            $jobs = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->where('failed_at', '>', now()->subDay())->count();
            $checks['queue'] = ['ok' => $jobs < 5000 && $failed < 50, 'waiting' => $jobs, 'failed_24h' => $failed];
            $backup = DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->latest('id')->value('created_at');
            $checks['backup'] = ['ok' => $backup && now()->diffInHours($backup, true) < 26, 'last' => $backup];
            $restore = DB::table('backup_runs')->where('kind', 'restore_test')->latest('id')->first(['status', 'created_at']);
            $checks['restore_test'] = ['ok' => $restore?->status === 'ok' && now()->diffInDays($restore->created_at, true) < 8, 'last' => $restore?->created_at];
            $checks['exams'] = ['ok' => true, 'open_attempts' => DB::table('attempts')->whereIn('status', ['in_progress', 'paused', 'locked'])->count()];
        } catch (\Throwable) {
            $checks['queue'] = ['ok' => false];
        }
        $free = @disk_free_space(storage_path()) ?: 0;
        $total = @disk_total_space(storage_path()) ?: 1;
        $checks['disk'] = ['ok' => $free / $total > 0.1, 'free_gb' => round($free / 1073741824, 1), 'free_pct' => round($free / $total * 100)];
        if (function_exists('sys_getloadavg')) {
            $checks['load'] = ['ok' => true, 'avg_1m' => sys_getloadavg()[0]];
        }

        $critical = ! $checks['database']['ok'] || ! $checks['cache']['ok'];
        $ok = collect($checks)->every(fn ($c) => $c['ok']);
        $status = $critical ? 'down' : ($ok ? 'ok' : 'degraded');
        $token = (string) config('platform.health_token');
        $detailed = $token !== '' && hash_equals($token, (string) $request->header('X-Health-Token'));

        return response()->json($detailed ? ['status' => $status, 'checks' => $checks, 'time' => now()->toIso8601String()] : ['status' => $status], $critical ? 503 : 200);
    }
}
