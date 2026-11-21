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
            ['slug' => SampleInstitution::$slug, 'name' => SampleInstitution::$name, 'short_name' => SampleInstitution::$short, 'brand_color' => '#10b981',
                'domains' => SampleInstitution::$hosts],
            'enterprise',
            ['name' => SampleInstitution::$short.' Administrator', 'email' => SampleInstitution::email('admin'), 'password' => $password]
        );

        if (! \App\Models\Subscription::where('tenant_id', $tenant->id)->exists()) {
            $tenant->forceFill(['approved_at' => now(), 'contact_name' => SampleInstitution::$short.' Administrator', 'contact_email' => SampleInstitution::email('admin')])->save();
            app(\App\Services\Billing\Billing::class)->start($tenant, \App\Models\Plan::where('key', 'enterprise')->firstOrFail(), 'yearly', null, \App\Services\Billing\Billing::trialDays());
        }
    }
}
