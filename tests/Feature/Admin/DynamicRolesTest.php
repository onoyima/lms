<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/** Roles belong to each institution: created, renamed, changed and given to anyone, with no fixed list behind them. */
class DynamicRolesTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    public function test_an_institution_creates_its_own_role_and_gives_it_to_any_staff_member(): void
    {
        $this->setUpPlatform();
        $this->actingAs($this->officer)->get('/admin/roles')->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/roles')->where('roles.0.name', 'institution_admin')->where('roles.0.editable', false));

        $this->actingAs($this->officer)->post('/admin/roles', ['label' => 'Timetable Desk', 'description' => 'Builds the exam timetable', 'permissions' => ['exams.manage']])->assertSessionHasNoErrors();
        $role = Role::where('tenant_id', tenant_id())->where('name', 'timetable_desk')->firstOrFail();
        $this->assertSame('Timetable Desk', $role->label);

        $staff = User::factory()->create(['email' => 'kemi@anything.example', 'user_type' => 'staff']);
        $this->actingAs($this->officer)->put("/admin/people/{$staff->id}", ['roles' => ['timetable_desk']])->assertSessionHasNoErrors();
        $this->assertTrue($staff->fresh()->can('exams.manage'));
        $this->actingAs($staff->fresh())->get('/exams')->assertOk();

        // rename and change what it may do: applies straight away
        $this->actingAs($this->officer)->put("/admin/roles/{$role->id}", ['label' => 'Timetable office', 'permissions' => ['exams.manage', 'results.publish']])->assertSessionHasNoErrors();
        $this->assertTrue(User::find($staff->id)->can('results.publish'));
        $this->actingAs($this->officer)->get('/admin/people')->assertInertia(fn ($p) => $p->where('roleLabels.timetable_desk', 'Timetable office'));

        // a role with members cannot be deleted; an empty one can
        $this->actingAs($this->officer)->delete("/admin/roles/{$role->id}")->assertStatus(422);
        $staff->syncRoles([]);
        $this->actingAs($this->officer)->delete("/admin/roles/{$role->id}")->assertRedirect();
        $this->assertNull(Role::find($role->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.created']);
    }

    public function test_nobody_can_create_or_grant_more_than_they_hold(): void
    {
        $this->setUpPlatform();
        $registrar = User::factory()->create(['user_type' => 'staff']);
        $registrar->assignRole('registry'); // users.manage + academics.manage, no roles.manage
        $this->actingAs($registrar)->get('/admin/roles')->assertForbidden();

        // someone allowed to manage roles but not billing cannot invent a billing role
        $ict = User::factory()->create(['user_type' => 'staff']);
        $ict->assignRole('ict_admin');
        $this->actingAs($ict)->post('/admin/roles', ['label' => 'Bursary', 'permissions' => ['billing.manage']])->assertForbidden();
        // nor change the role they hold themselves, nor the administrator role
        $own = Role::where('tenant_id', tenant_id())->where('name', 'ict_admin')->first();
        $this->actingAs($ict)->put("/admin/roles/{$own->id}", ['label' => 'ICT', 'permissions' => ['institution.manage']])->assertForbidden();
        $admin = Role::where('tenant_id', tenant_id())->where('name', 'institution_admin')->first();
        $this->actingAs($this->officer)->put("/admin/roles/{$admin->id}", ['label' => 'Admin', 'permissions' => []])->assertForbidden();

        // and cannot hand a role holding billing to someone else under People
        $this->actingAs($this->officer)->post('/admin/roles', ['label' => 'Bursary', 'permissions' => ['billing.manage']])->assertSessionHasNoErrors();
        $x = User::factory()->create(['user_type' => 'staff']);
        $this->actingAs($ict)->put("/admin/people/{$x->id}", ['roles' => ['bursary']])->assertForbidden();
        // the bursary role sees billing, though it is not the administrator role
        $x->assignRole('bursary');
        $this->actingAs($x->fresh())->get('/institution/billing')->assertOk();
        $this->actingAs($ict)->get('/institution/billing')->assertForbidden();
    }

    public function test_starting_roles_can_be_reset_and_survive_reprovisioning(): void
    {
        $this->setUpPlatform();
        $lecturer = Role::where('tenant_id', tenant_id())->where('name', 'lecturer')->first();
        $this->actingAs($this->officer)->put("/admin/roles/{$lecturer->id}", ['label' => 'Tutor', 'permissions' => ['lms.teach']])->assertSessionHasNoErrors();
        app(\App\Services\Tenancy\TenantProvisioner::class)->provision(['slug' => $this->tenant->slug, 'name' => $this->tenant->name], 'enterprise');
        $this->assertSame(['lms.teach'], $lecturer->fresh()->permissions->pluck('name')->all());
        $this->assertSame('Tutor', $lecturer->fresh()->label);

        $this->actingAs($this->officer)->post("/admin/roles/{$lecturer->id}/reset")->assertRedirect();
        $this->assertEqualsCanonicalizing(config('roles.roles.lecturer'), $lecturer->fresh()->permissions->pluck('name')->all());
    }

    public function test_reports_and_rosters_follow_permissions_not_role_names(): void
    {
        $this->setUpPlatform();
        $this->actingAs($this->officer)->post('/admin/roles', ['label' => 'Quality office', 'permissions' => ['reports.all_departments', 'audit.view']]);
        $q = User::factory()->create(['user_type' => 'staff']);
        $q->assignRole('quality_office');
        $this->assertNull(app(\App\Services\Reports\ReportCatalog::class)->scope($q->fresh()));

        $this->actingAs($this->officer)->post('/admin/roles', ['label' => 'Hall marshal', 'permissions' => ['exams.invigilate']]);
        $m = User::factory()->create(['user_type' => 'staff', 'name' => 'Marshal']);
        $m->assignRole('hall_marshal');
        $this->assertTrue(User::permission(['exams.proctor', 'exams.invigilate', 'exams.lead_hall'])->whereKey($m->id)->exists());
    }
}
