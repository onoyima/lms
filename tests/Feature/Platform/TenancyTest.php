<?php

namespace Tests\Feature\Platform;

use App\Models\Course;
use App\Models\Question;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_institutions_never_see_each_others_data(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other University']);
        $context = app(TenantContext::class);

        Course::create(['code' => 'CHM 101', 'title' => 'General Chemistry']);
        $context->run($other, fn () => Course::create(['code' => 'PHY 101', 'title' => 'General Physics']));

        $this->assertSame(['CHM 101'], Course::pluck('code')->all());
        $this->assertSame(['PHY 101'], $context->run($other, fn () => Course::pluck('code')->all()));
    }

    public function test_new_rows_are_stamped_with_the_current_institution(): void
    {
        $q = Question::create(['stem_html' => '<p>x</p>']);

        $this->assertSame($this->tenant->id, $q->tenant_id);
    }

    public function test_without_an_institution_queries_return_nothing(): void
    {
        Course::create(['code' => 'CHM 101', 'title' => 'General Chemistry']);

        $count = app(TenantContext::class)->run(null, fn () => Course::count());

        $this->assertSame(0, $count);
    }

    public function test_a_user_cannot_sign_in_on_another_institutions_address(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'domains' => ['exams.other.test']]);
        $user = app(TenantContext::class)->run($other, fn () => User::factory()->create());

        // Default host resolves to the test institution, where this account does not exist
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->assertGuest();

        // On the other institution's own address it works
        $this->post('http://exams.other.test/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_route_bound_records_from_another_institution_are_not_found(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        $foreignImport = app(TenantContext::class)->run($other, fn () => \App\Models\QuestionImport::create([
            'original_name' => 'x.txt', 'stored_path' => 'x/original.txt', 'format' => 'txt',
        ]));

        $admin = $this->adminUser();

        $this->actingAs($admin)->get("/question-bank/imports/{$foreignImport->id}")->assertNotFound();
    }

    public function test_a_real_sign_in_reaches_the_dashboard(): void
    {
        // Regression: the institution must be resolved before the session user is loaded.
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
    }

    private function adminUser(): User
    {
        \App\Services\Tenancy\TenantProvisioner::syncPermissions();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->tenant->update(['plan_id' => \App\Models\Plan::where('key', 'enterprise')->value('id')]);
        app(\App\Services\Tenancy\TenantProvisioner::class)->provision(['slug' => $this->tenant->slug, 'name' => $this->tenant->name], 'enterprise');
        $user = User::factory()->create();
        $user->assignRole('institution_admin');

        return $user;
    }
}
