<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * A fresh platform: the plans and the first platform administrator. Institutions are added from the
     * platform console (or, for a single-institution install, from SEED_INSTITUTION_* in .env).
     * Passwords come from SEED_ADMIN_PASSWORD or are generated and printed once.
     * Sample institutions and people for trying things out: php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        $password = env('SEED_ADMIN_PASSWORD') ?: Str::password(16);
        $made = [];

        // the platform's own administrator (only when there is none yet, so re-running never adds a second)
        if (! User::withoutGlobalScopes()->whereNull('tenant_id')->where('is_platform_admin', true)->exists()) {
            $email = env('SEED_PLATFORM_EMAIL', 'platform@platform.local');
            $platform = User::withoutGlobalScopes()->firstOrNew(['email' => $email, 'tenant_id' => null]);
            $platform->forceFill([
                'name' => 'Platform Administrator', 'password' => $password,
                'is_platform_admin' => true, 'email_verified_at' => now(), 'user_type' => 'platform',
            ])->save();
            $made[] = $email;
        }

        // optional: the first institution of a single-institution install
        if (($slug = env('SEED_INSTITUTION_SLUG')) && ! \App\Models\Tenant::where('slug', $slug)->exists()) {
            $adminEmail = env('SEED_INSTITUTION_ADMIN_EMAIL', 'admin@'.$slug.'.local');
            $tenant = app(TenantProvisioner::class)->provision(
                ['slug' => $slug, 'name' => env('SEED_INSTITUTION_NAME', Str::headline($slug)), 'short_name' => env('SEED_INSTITUTION_SHORT_NAME', Str::headline($slug)),
                    'domains' => ['localhost', '127.0.0.1']],
                'enterprise',
                ['name' => 'Institution Administrator', 'email' => $adminEmail, 'password' => $password]
            );
            $tenant->forceFill(['approved_at' => now(), 'contact_name' => 'Institution Administrator', 'contact_email' => $adminEmail])->save();
            app(\App\Services\Billing\Billing::class)->start($tenant, \App\Models\Plan::where('key', 'enterprise')->firstOrFail(), 'yearly', null, \App\Services\Billing\Billing::trialDays());
            $made[] = $adminEmail;
        }

        if ($made && ! env('SEED_ADMIN_PASSWORD')) {
            $this->command?->warn('Generated password for '.implode(' and ', $made).": {$password}");
        }
    }
}
