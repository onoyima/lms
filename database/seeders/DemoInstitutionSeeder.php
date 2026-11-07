<?php

namespace Database\Seeders;

use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;

/**
 * Sample data only: Veritas University as an example institution on the platform, with its
 * administrator admin@veritas.local. Used by DemoSeeder and the tests; never on a live database.
 */
class DemoInstitutionSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_ADMIN_PASSWORD') ?: 'ChangeMe!2026';
        $tenant = app(TenantProvisioner::class)->provision(
            ['slug' => 'veritas', 'name' => 'Veritas University, Abuja', 'short_name' => 'Veritas', 'brand_color' => '#10b981',
                'domains' => ['localhost', '127.0.0.1']],
            'enterprise',
            ['name' => 'Veritas Administrator', 'email' => 'admin@veritas.local', 'password' => $password]
        );

        if (! \App\Models\Subscription::where('tenant_id', $tenant->id)->exists()) {
            $tenant->forceFill(['approved_at' => now(), 'contact_name' => 'Veritas Administrator', 'contact_email' => 'admin@veritas.local'])->save();
            app(\App\Services\Billing\Billing::class)->start($tenant, \App\Models\Plan::where('key', 'enterprise')->firstOrFail(), 'yearly', null, \App\Services\Billing\Billing::trialDays());
        }
    }
}
