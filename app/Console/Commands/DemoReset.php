<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Platform\PlatformSettings;
use Database\Seeders\DemoSeeder;
use Database\Seeders\FeatureCoverageSeeder;
use Database\Seeders\SampleInstitution;
use Database\Seeders\ScenarioSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The public demo: a separate sample institution ("Demo University") that visitors can sign in to
 * as any role, wiped and filled again with sample data. It only ever deletes the institution with
 * the slug "demo" that is marked as the demo; no other institution, and nothing platform-wide, is
 * touched. Off unless the platform administrator switches the public demo on.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset {--force : run even when the public demo is switched off}';

    protected $description = 'Wipe and refill the public demo institution with sample data';

    public function handle(): int
    {
        if (! $this->option('force') && ! PlatformSettings::all()['demo_enabled']) {
            $this->info('The public demo is switched off (Platform branding). Nothing done.');

            return self::SUCCESS;
        }
        if (! DB::table('plans')->where('key', 'enterprise')->exists()) {
            $this->error('Plans are missing; run the plan seeder first.');

            return self::FAILURE;
        }

        $old = Tenant::where('slug', 'demo')->first();
        if ($old && ! data_get($old->settings, 'demo')) {
            $this->error('An institution with the address "demo" exists and is not the demo. Nothing done.');

            return self::FAILURE;
        }
        if ($old) {
            $this->purge((int) $old->id);
        }

        auth()->forgetGuards(); // nobody is signed in while the demo is filled
        if (app()->runningInConsole() && app()->bound('session.store')) {
            app('session.store')->forget(auth()->guard('web')->getName());
        }
        SampleInstitution::useDemo();
        // nobody knows these passwords: visitors sign in with the role buttons on /demo
        putenv('SEED_ADMIN_PASSWORD='.Str::random(40));
        try {
            $this->callSilently('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $this->callSilently('db:seed', ['--class' => ScenarioSeeder::class, '--force' => true]);
            $this->callSilently('db:seed', ['--class' => FeatureCoverageSeeder::class, '--force' => true]);
        } finally {
            putenv('SEED_ADMIN_PASSWORD');
            SampleInstitution::reset();
            app(\App\Support\Tenancy\TenantContext::class)->set(null);
        }

        $tenant = Tenant::where('slug', 'demo')->firstOrFail();
        $tenant->forceFill(['settings' => array_replace((array) $tenant->settings, ['demo' => true]), 'domains' => []])->save();
        $this->info('The public demo has been refilled.');

        return self::SUCCESS;
    }

    /**
     * Delete the demo institution's rows from every table that has a tenant_id, in as many passes as
     * the foreign keys need, then the institution itself (whatever is left cascades from it). Only
     * rows with this institution's id are ever touched.
     */
    private function purge(int $tenantId): void
    {
        $tables = collect(\Illuminate\Support\Facades\Schema::getTables())->pluck('name')
            ->filter(fn ($t) => $t !== 'tenants' && \Illuminate\Support\Facades\Schema::hasColumn($t, 'tenant_id'))->values();
        for ($pass = 0; $pass < 8 && $tables->isNotEmpty(); $pass++) {
            $tables = $tables->filter(function ($t) use ($tenantId) {
                try {
                    DB::table($t)->where('tenant_id', $tenantId)->delete();

                    return false;
                } catch (\Illuminate\Database\QueryException) {
                    return true; // another table still points at these rows: try again next pass
                }
            })->values();
        }
        DB::table('tenants')->where('id', $tenantId)->delete();
    }
}
