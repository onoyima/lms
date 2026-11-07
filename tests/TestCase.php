<?php

namespace Tests;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite(); // page bundles are built by `npm run build`; tests check the server side
        \App\Support\Platform\PlatformSettings::flush(); // per-process memo must not leak between tests

        // Every database test runs inside a default institution, like a single-institution install.
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            config(['platform.default_tenant' => config('platform.default_tenant') ?: 'test-university']);
            $this->tenant = Tenant::create(['slug' => config('platform.default_tenant'), 'name' => 'Test University']);
            app(TenantContext::class)->set($this->tenant);
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
