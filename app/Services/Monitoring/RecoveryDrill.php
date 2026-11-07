<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Failover and recovery drill (Target 186). Measures, rather than assumes, how the platform
 * recovers: a fresh backup, a full restore into a scratch database on the same local server with
 * row counts compared, the database replica (if one is configured) receiving a write within
 * the allowed lag, and each peer application server answering its health check.
 * Records how long recovery took (RTO) and how much data could be lost (RPO).
 * Only the connections already configured for this platform are used; nothing remote is added.
 */
class RecoveryDrill
{
    public function run(?int $userId = null): array
    {
        $id = DB::table('dr_drills')->insertGetId(['status' => 'running', 'started_by' => $userId, 'started_at' => now()]);
        $steps = [];
        $step = function (string $name, callable $fn) use (&$steps) {
            $t = microtime(true);
            try {
                [$ok, $detail] = $fn();
            } catch (\Throwable $e) {
                [$ok, $detail] = [false, mb_substr($e->getMessage(), 0, 300)];
            }
            $steps[] = ['step' => $name, 'ok' => $ok, 'seconds' => round(microtime(true) - $t, 1), 'detail' => $detail];

            return $ok;
        };

        $step('Take a fresh backup', function () {
            $code = Artisan::call('backup:run');

            return [$code === 0, trim(collect(explode("\n", Artisan::output()))->filter()->last() ?? '')];
        });
        $restoreStart = microtime(true);
        $restored = $step('Restore it into a scratch database and compare', function () {
            $code = Artisan::call('backup:run', ['--restore-test' => true]);

            return [$code === 0, $code === 0 ? 'Row counts match the live database.' : trim(collect(explode("\n", Artisan::output()))->filter()->last() ?? 'Restore failed')];
        });
        $rto = (int) round(microtime(true) - $restoreStart);

        $replicaLag = null;
        if (config('database.connections.replica')) {
            $step('Replica receives a new write', function () use (&$replicaLag) {
                $marker = 'drill-'.bin2hex(random_bytes(6));
                DB::table('cache')->insert(['key' => 'dr:'.$marker, 'value' => '1', 'expiration' => now()->addHour()->getTimestamp()]);
                $t = microtime(true);
                do {
                    $seen = DB::connection('replica')->table('cache')->where('key', 'dr:'.$marker)->exists();
                    $seen || usleep(250000);
                } while (! $seen && microtime(true) - $t < 30);
                DB::table('cache')->where('key', 'dr:'.$marker)->delete();
                $replicaLag = round(microtime(true) - $t, 1);

                return [$seen, $seen ? "Copied to the replica in {$replicaLag} s." : 'Not on the replica after 30 s.'];
            });
        } else {
            $steps[] = ['step' => 'Replica receives a new write', 'ok' => null, 'seconds' => 0, 'detail' => 'No replica configured (single server). See deploy/ha.'];
        }

        foreach (array_filter(array_map('trim', explode(',', (string) config('platform.dr_peers')))) as $peer) {
            $step("Peer server answers: $peer", function () use ($peer) {
                $r = Http::timeout(10)->get(rtrim($peer, '/').'/api/health');

                return [$r->successful() && in_array($r->json('status'), ['ok', 'degraded'], true), 'status '.$r->status().' '.($r->json('status') ?? '')];
            });
        }

        $lastBackup = DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->max('created_at');
        $rpo = $replicaLag !== null ? (int) ceil($replicaLag) : ($lastBackup ? (int) now()->diffInSeconds($lastBackup, true) : null);
        $passed = collect($steps)->every(fn ($s) => $s['ok'] !== false);
        DB::table('dr_drills')->where('id', $id)->update(['status' => $passed ? 'passed' : 'failed', 'steps' => json_encode($steps), 'rto_seconds' => $restored ? $rto : null,
            'rpo_seconds' => $rpo, 'finished_at' => now()]);

        return ['id' => $id, 'passed' => $passed, 'steps' => $steps, 'rto_seconds' => $rto, 'rpo_seconds' => $rpo];
    }
}
