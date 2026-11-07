<?php

namespace Tests\Feature\Platform;

use App\Models\Plan;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use App\Services\Tenancy\TenantProvisioner;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function usePlan(string $key): void
    {
        $this->tenant->update(['plan_id' => Plan::where('key', $key)->value('id')]);
        $this->tenant->refresh();
    }

    public function test_an_exams_only_school_gets_exams_and_nothing_else(): void
    {
        $this->usePlan('exams_only');
        $enabled = app(ModuleRegistry::class)->enabledFor($this->tenant);

        $this->assertEqualsCanonicalizing(['core', 'question_bank', 'browser_proctoring'], $enabled);
    }

    public function test_an_add_on_can_be_switched_on_without_changing_the_plan(): void
    {
        $this->usePlan('exams_only');
        $this->tenant->moduleOverrides()->create(['module' => 'lms', 'enabled' => true]);

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($this->tenant->fresh(), 'lms'));
    }

    public function test_a_module_whose_requirement_is_off_stays_off(): void
    {
        $this->usePlan('enterprise');
        $this->tenant->moduleOverrides()->create(['module' => 'browser_proctoring', 'enabled' => false]);
        $enabled = app(ModuleRegistry::class)->enabledFor($this->tenant->fresh());

        $this->assertNotContains('browser_proctoring', $enabled);
        $this->assertNotContains('live_proctoring', $enabled); // needs browser_proctoring
        $this->assertContains('question_bank', $enabled);
    }

    public function test_core_cannot_be_switched_off(): void
    {
        $this->usePlan('exams_only');
        $this->tenant->moduleOverrides()->create(['module' => 'core', 'enabled' => false]);

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($this->tenant->fresh(), 'core'));
    }

    public function test_routes_of_a_module_that_is_off_are_refused(): void
    {
        $this->tenant->update(['plan_id' => null]); // core only
        app(TenantProvisioner::class)->provision(['slug' => $this->tenant->slug, 'name' => $this->tenant->name], 'starter');
        $user = User::factory()->create();
        $user->assignRole('institution_admin');

        $this->actingAs($user)->get('/question-bank')->assertForbidden();

        $this->usePlan('starter');
        $this->actingAs($user)->get('/question-bank')->assertOk();
    }

    public function test_only_platform_staff_can_change_plans(): void
    {
        app(TenantProvisioner::class)->provision(['slug' => $this->tenant->slug, 'name' => $this->tenant->name], 'starter');
        $admin = User::factory()->create();
        $admin->assignRole('institution_admin');

        $this->actingAs($admin)->get('/platform/modules')->assertForbidden();

        $platform = User::factory()->create(['tenant_id' => null]);
        $platform->forceFill(['is_platform_admin' => true, 'tenant_id' => null])->save();

        $this->actingAs($platform)->get('/platform/modules')->assertOk();
        $this->actingAs($platform)
            ->put("/platform/modules/{$this->tenant->id}", ['plan_id' => Plan::where('key', 'exams_only')->value('id'), 'overrides' => ['lms' => true]])
            ->assertRedirect();

        $this->assertTrue(app(ModuleRegistry::class)->isEnabled($this->tenant->fresh(), 'lms'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'modules.changed', 'tenant_id' => $this->tenant->id]);
    }
}
