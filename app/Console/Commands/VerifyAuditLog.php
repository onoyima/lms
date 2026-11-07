<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

class VerifyAuditLog extends Command
{
    protected $signature = 'audit:verify {tenant? : institution slug (omit for all)}';

    protected $description = 'Check that no audit log entry has been edited or deleted';

    public function handle(AuditLogger $audit): int
    {
        $tenants = $this->argument('tenant')
            ? Tenant::where('slug', $this->argument('tenant'))->get()
            : Tenant::all();

        $failed = false;
        foreach ([null, ...$tenants] as $tenant) {
            $label = $tenant?->slug ?? 'platform';
            $broken = $audit->verify($tenant?->id);
            if ($broken) {
                $failed = true;
                $this->error("$label: chain broken at audit log #$broken");
            } else {
                $this->info("$label: intact");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
