<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Directory\DirectoryException;
use App\Services\Directory\DirectorySync;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Bus\Dispatchable;

/** One directory sync for one institution (run after the "Sync now" page has answered, or by the scheduler). */
class SyncDirectory
{
    use Dispatchable;

    public function __construct(public int $tenantId, public bool $force = false) {}

    public function handle(TenantContext $context, DirectorySync $sync): void
    {
        @set_time_limit(0);
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }
        $context->run($tenant, function () use ($sync) {
            try {
                $sync->run($this->force);
            } catch (DirectoryException $e) {
                // already running, or switched off: nothing to record twice
                \Illuminate\Support\Facades\Log::info('directory sync skipped: '.$e->getMessage(), ['tenant' => $this->tenantId]);
            }
        });
    }
}
