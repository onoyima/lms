<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class PeopleTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    public function test_admin_creates_accounts_and_registry_cannot_escalate(): void
    {
        Notification::fake();
        $this->setUpPlatform();
        $this->actingAs($this->officer)->post('/admin/people', ['name' => 'Dr Bello', 'email' => 'bello@veritas.edu.ng', 'identifier' => 'STF/9', 'user_type' => 'staff', 'roles' => ['lecturer', 'hod']])->assertSessionHasNoErrors();
        $bello = User::where('email', 'bello@veritas.edu.ng')->first();
        $this->assertTrue($bello->hasRole('hod'));

        $registry = User::factory()->create();
        $registry->assignRole('registry');                 // users.manage + academics.manage only
        $this->actingAs($registry)->get('/admin/people')->assertOk();
        $this->post('/admin/people', ['name' => 'X', 'email' => 'x@v.ng', 'user_type' => 'staff', 'roles' => ['institution_admin']])->assertForbidden();
        $this->post('/admin/people', ['name' => 'Y', 'email' => 'y@v.ng', 'user_type' => 'staff', 'roles' => ['exam_officer']])->assertForbidden();
        $this->put("/admin/people/{$registry->id}", ['roles' => ['institution_admin']])->assertForbidden(); // own roles
        $student = User::factory()->create(['user_type' => 'student']);
        $this->actingAs($student)->get('/admin/people')->assertForbidden();
    }

    public function test_temporary_roles_expire_and_deactivation_blocks_sign_in(): void
    {
        $this->setUpPlatform();
        $acting = User::factory()->create(['password' => 'secret-pass-1']);
        $acting->assignRole('lecturer');
        $this->actingAs($this->officer)->post("/admin/people/{$acting->id}/delegate", ['role' => 'hod', 'expires_at' => now()->addDays(2)->toDateTimeString(), 'reason' => 'Acting HOD during leave'])->assertSessionHasNoErrors();
        $this->assertTrue($acting->fresh()->hasRole('hod'));
        $this->travel(3)->days();
        $this->artisan('roles:expire')->assertSuccessful();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($acting->fresh()->hasRole('hod'));
        $this->assertTrue($acting->fresh()->hasRole('lecturer'));

        $this->put("/admin/people/{$acting->id}", ['status' => 'inactive'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->post('/login', ['email' => $acting->email, 'password' => 'secret-pass-1']);
        $this->assertGuest();
    }

    public function test_csv_import_and_timetable_clashes(): void
    {
        $this->setUpPlatform();
        $csv = "name,email,identifier,type,roles\nAda,ada@v.ng,VUG/1,student,\nDr K,k@v.ng,STF/2,staff,lecturer;invigilator\nBad row,not-an-email,,student,\n";
        $this->actingAs($this->officer)->post('/admin/people/import', ['file' => UploadedFile::fake()->createWithContent('people.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'k@v.ng')->first()->hasRole('invigilator'));
        $this->assertSame(1, User::where('email', 'ada@v.ng')->count());

        // Same student in two overlapping sittings → clash shown to the exams office
        $ada = User::where('email', 'ada@v.ng')->first();
        $a = $this->sitting($this->cbtExam(['title' => 'CHM 101']));
        $b = $this->sitting($this->cbtExam(['title' => 'PHY 101'], []));
        $this->candidate($a, $ada);
        $this->candidate($b, $ada);
        $props = $this->get("/sessions/{$a->id}")->assertOk()->viewData('page')['props'];
        $this->assertCount(1, $props['clashes']);
        $this->assertSame('PHY 101', $props['clashes'][0]->other_exam ?? $props['clashes'][0]['other_exam']);
    }
}
