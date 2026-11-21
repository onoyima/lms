<?php

namespace Tests\Feature\System;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Platform\PlatformSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** The public demo: its own sample institution, sign-in by role, no passwords, risky changes switched off. */
class PublicDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        \Database\Seeders\SampleInstitution::reset();
    }

    public function test_the_demo_is_off_until_the_platform_switches_it_on(): void
    {
        Artisan::call('demo:reset');
        $this->assertFalse(Tenant::where('slug', 'demo')->exists());
        $this->post('/demo/student')->assertNotFound();
        $this->get('/demo')->assertOk()->assertInertia(fn ($p) => $p->where('available', false));
    }

    public function test_visitors_try_a_role_in_a_separate_institution_and_cannot_change_risky_settings(): void
    {
        PlatformSettings::put(['demo_enabled' => true], null);
        $this->assertSame(0, Artisan::call('demo:reset'));
        $demo = Tenant::where('slug', 'demo')->firstOrFail();
        $this->assertTrue($demo->isDemo());
        $this->assertSame([], $demo->domains);
        $this->assertSame(0, User::withoutGlobalScopes()->where('tenant_id', $demo->id)->where('email', 'not like', '%.invalid')->count()); // no real address can be reached

        $this->post('/demo/examofficer')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs(User::withoutGlobalScopes()->where('email', 'examofficer@demo.invalid')->first());
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('demo', true));
        $this->putJson('/settings/password', ['current_password' => 'x', 'password' => 'Abcdefgh1!x', 'password_confirmation' => 'Abcdefgh1!x'])->assertForbidden();
        $this->postJson('/admin/roles', ['name' => 'x'])->assertForbidden();
        $this->post('/demo/nobody')->assertNotFound();

        // refilling replaces the demo, and touches no other institution
        $others = Tenant::where('slug', '!=', 'demo')->count();
        auth()->forgetGuards(); // the nightly refill runs with nobody signed in
        $this->assertSame(0, Artisan::call('demo:reset'));
        $this->assertSame($others, Tenant::where('slug', '!=', 'demo')->count());
        $this->assertSame(1, Tenant::where('slug', 'demo')->count());
    }

    public function test_an_institution_that_happens_to_use_the_address_demo_is_never_wiped(): void
    {
        PlatformSettings::put(['demo_enabled' => true], null);
        $real = Tenant::create(['slug' => 'demo', 'name' => 'A real institution']);
        $this->assertSame(1, Artisan::call('demo:reset'));
        $this->assertTrue(Tenant::whereKey($real->id)->exists());
    }
}
