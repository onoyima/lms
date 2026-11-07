<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Planned maintenance windows for this institution and for the whole platform. */
class Maintenance
{
    /** Windows that are running or start within two days, soonest first (cached for a minute). */
    public static function upcoming(): array
    {
        $tid = tenant_id();

        return Cache::remember('maint:'.($tid ?? 'p'), 60, fn () => DB::table('maintenance_windows')
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tid, fn ($w) => $w->orWhere('tenant_id', $tid)))
            ->where('ends_at', '>', now())->where('starts_at', '<', now()->addDays(2))->orderBy('starts_at')
            ->get(['id', 'starts_at', 'ends_at', 'message', 'blocks_exams'])->map(fn ($w) => (array) $w)->all());
    }

    public static function blocksExamsAt(CarbonInterface $from, ?CarbonInterface $to = null): ?array
    {
        $tid = tenant_id();
        $w = DB::table('maintenance_windows')->where('blocks_exams', true)
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tid, fn ($x) => $x->orWhere('tenant_id', $tid)))
            ->where('starts_at', '<', $to ?? $from)->where('ends_at', '>', $from)->first();

        return $w ? (array) $w : null;
    }

    public static function forget(?int $tenantId): void
    {
        Cache::forget('maint:'.($tenantId ?? 'p'));
    }
}
