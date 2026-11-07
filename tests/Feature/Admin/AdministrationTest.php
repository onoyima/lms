<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Enrolment;
use App\Models\Faculty;
use App\Models\Semester;
use App\Models\User;
use App\Services\Reports\ReportCatalog;
use App\Support\Exams\ExamSettings;
use App\Support\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    public function test_academic_structure_prerequisite_cycles_rollover_and_streams(): void
    {
        $this->setUpPlatform();
        $this->actingAs($this->officer);
        $this->post('/admin/academics/units/faculty', ['name' => 'Natural Sciences', 'code' => 'nas'])->assertSessionHasNoErrors();
        $fac = Faculty::where('code', 'NAS')->firstOrFail();
        $this->post('/admin/academics/units/faculty', ['name' => 'Duplicate', 'code' => 'NAS'])->assertSessionHasErrors('code');
        $this->post('/admin/academics/units/department', ['name' => 'Chemistry', 'code' => 'CHM', 'faculty_id' => $fac->id])->assertSessionHasNoErrors();
        $dep = Department::where('code', 'CHM')->first();

        $this->post('/admin/academics/courses', ['code' => 'chm 101', 'title' => 'General Chemistry', 'credit_units' => 3, 'level' => 100, 'department_id' => $dep->id])->assertSessionHasNoErrors();
        $a = Course::where('code', 'CHM 101')->firstOrFail();
        $this->post('/admin/academics/courses', ['code' => 'CHM 201', 'title' => 'Organic', 'credit_units' => 3, 'level' => 200, 'department_id' => $dep->id, 'prerequisites' => [$a->id]])->assertSessionHasNoErrors();
        $b = Course::where('code', 'CHM 201')->first();
        // CHM 101 requiring CHM 201 would be a circle
        $this->post('/admin/academics/courses', ['id' => $a->id, 'code' => 'CHM 101', 'title' => 'General Chemistry', 'credit_units' => 3, 'prerequisites' => [$b->id]])->assertSessionHasErrors('prerequisites');
        // Department in use cannot be deleted
        $this->delete("/admin/academics/units/department/{$dep->id}")->assertSessionHasErrors('delete');

        $this->post('/admin/academics/sessions', ['name' => '2026/2027', 'semesters' => ['First semester', 'Second semester']])->assertSessionHasNoErrors();
        $this->post('/admin/academics/sessions', ['name' => 'bad name'])->assertSessionHasErrors('name');
        [$first, $second] = Semester::orderBy('id')->get()->all();
        $this->post("/admin/academics/semesters/{$first->id}", ['action' => 'activate'])->assertSessionHasNoErrors();
        $this->assertTrue($first->fresh()->is_current);

        $lect = User::factory()->create(['user_type' => 'staff']);
        $this->post('/admin/academics/offerings', ['course_id' => $a->id, 'semester_id' => $first->id, 'capacity' => 300, 'status' => 'open',
            'staff' => [['user_id' => $lect->id, 'role' => 'coordinator']]])->assertSessionHasNoErrors();
        $o = CourseOffering::first();
        $this->assertTrue($lect->fresh()->hasRole('lecturer'));
        foreach (range(1, 12) as $i) {
            Enrolment::create(['course_offering_id' => $o->id, 'user_id' => User::factory()->create(['user_type' => 'student'])->id, 'status' => 'active']);
        }
        $this->post("/admin/academics/offerings/{$o->id}/streams", ['size' => 5])->assertSessionHasNoErrors();
        $this->assertEquals(['A' => 5, 'B' => 5, 'C' => 2], DB::table('enrolments')->groupBy('stream')->selectRaw('stream, COUNT(*) n')->pluck('n', 'stream')->all());

        $this->post("/admin/academics/semesters/{$second->id}", ['action' => 'rollover', 'from' => $first->id])->assertSessionHasNoErrors();
        $this->assertTrue(CourseOffering::where('semester_id', $second->id)->first()->staff->contains($lect));
        $this->post("/admin/academics/semesters/{$first->id}", ['action' => 'close'])->assertSessionHasNoErrors();
        $this->assertSame('archived', $o->fresh()->status);

        $student = User::factory()->create(['user_type' => 'student']);
        $this->actingAs($student)->get('/admin/academics')->assertForbidden();
    }

    public function test_policy_drives_grades_severity_exam_defaults_and_email_domains(): void
    {
        $this->setUpPlatform();
        $policy = Policy::DEFAULTS;
        $policy['grading_scale'] = ['A' => 75, 'B' => 65, 'C' => 55, 'D' => 50, 'F' => 0];
        $policy['grade_points'] = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1, 'F' => 0];
        $policy['pass_grade_min'] = 'D';
        $policy['email_domains'] = ['veritas.edu.ng'];
        $policy['severity_overrides'] = ['tab_hidden' => 5];
        $policy['exam_type_defaults'] = ['cbt' => ['webcam' => true, 'violation_threshold' => 3]];
        $this->actingAs($this->officer)->put('/admin/policy', $policy)->assertSessionHasNoErrors();

        $this->assertSame('B', Policy::grade(70));
        $this->assertSame('F', Policy::grade(49));
        $this->assertSame(5, ExamSettings::severity('tab_hidden'));
        $this->post('/exams', ['title' => 'New CBT', 'type' => 'cbt', 'duration_minutes' => 30, 'max_attempts' => 1, 'score_policy' => 'highest', 'category' => 'exam'])->assertRedirect();
        $exam = \App\Models\Exam::where('title', 'New CBT')->first();
        $this->assertTrue($exam->proctoring['webcam']);
        $this->assertSame(3, $exam->proctoring['violation_threshold']);

        $this->post('/admin/people', ['name' => 'Outsider', 'email' => 'x@gmail.com', 'user_type' => 'staff'])->assertStatus(422);
        $this->post('/admin/people', ['name' => 'Insider', 'email' => 'x@chm.veritas.edu.ng', 'user_type' => 'staff'])->assertSessionHasNoErrors();

        $this->put('/admin/policy', array_merge($policy, ['pass_grade_min' => 'Z']))->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'policy.updated']);
    }

    public function test_every_report_runs_and_exports_with_approval_for_personal_data(): void
    {
        $this->setUpPlatform();
        $session = AcademicSession::create(['name' => '2026/2027']);
        $sem = Semester::create(['academic_session_id' => $session->id, 'name' => 'First', 'is_current' => true]);
        $course = Course::create(['code' => 'CHM 101', 'title' => '=CMD()', 'credit_units' => 3, 'level' => 100]);
        $o = CourseOffering::create(['course_id' => $course->id, 'semester_id' => $sem->id, 'status' => 'open']);
        $o->staff()->attach($this->officer->id, ['role' => 'coordinator']);
        $exam = $this->cbtExam();
        $c = $this->candidate($this->sitting($exam));
        Enrolment::create(['course_offering_id' => $o->id, 'user_id' => $c->user_id, 'status' => 'active']);

        $catalog = app(ReportCatalog::class);
        foreach (array_keys($catalog->definitions()) as $key) {
            $r = $catalog->run($key, [], $this->officer);
            $this->assertNotEmpty($r['columns'], $key);
        }
        $this->actingAs($this->officer)->get('/admin/reports?report=registrations')->assertOk();

        $xlsx = $this->get('/admin/reports/registrations/export/xlsx')->assertOk();
        $file = $xlsx->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('&apos;=CMD()', $xml); // formula neutralised
        $pdf = $this->get('/admin/reports/course_coverage/export/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Personal data over the threshold needs another admin's approval
        $tenant = tenant();
        $tenant->settings = ['policy' => ['export_approval_rows' => 0]];
        $tenant->save();
        $this->get('/admin/reports/student_registrations/export/csv')->assertSessionHasErrors('export');
        $this->post('/admin/reports/student_registrations/request/csv', ['reason' => 'Senate meeting list'])->assertSessionHasNoErrors();
        $id = DB::table('export_requests')->value('id');
        $this->post("/admin/reports/requests/$id", ['approve' => true])->assertForbidden(); // not your own
        $other = User::factory()->create();
        $other->assignRole('institution_admin');
        $this->actingAs($other)->post("/admin/reports/requests/$id", ['approve' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->officer)->get('/admin/reports/student_registrations/export/csv')->assertOk();
        $this->get('/admin/reports/student_registrations/export/csv')->assertSessionHasErrors('export'); // single use
    }

    public function test_hod_sees_only_their_department_and_access_review_and_mfa(): void
    {
        $this->setUpPlatform();
        $fac = Faculty::create(['name' => 'Sci', 'code' => 'S']);
        $d1 = Department::create(['faculty_id' => $fac->id, 'name' => 'Chem', 'code' => 'C1']);
        $d2 = Department::create(['faculty_id' => $fac->id, 'name' => 'Phys', 'code' => 'P1']);
        $hod = User::factory()->create(['user_type' => 'staff']);
        $hod->assignRole('hod');
        $this->actingAs($this->officer)->put("/admin/people/{$hod->id}/placement", ['department_id' => $d1->id])->assertSessionHasNoErrors();
        $this->assertSame([$d1->id], app(ReportCatalog::class)->scope($hod->fresh()));
        $this->assertSame($fac->id, DB::table('staff_profiles')->where('user_id', $hod->id)->value('faculty_id'));

        $this->get('/admin/access-review')->assertOk();
        $this->post('/admin/access-review', ['decisions' => [['user_id' => $hod->id, 'role' => 'hod', 'keep' => false, 'note' => 'Left the post']]])->assertSessionHasNoErrors();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($hod->fresh()->hasRole('hod'));

        config(['platform.enforce_mfa' => true]);
        $this->get('/dashboard')->assertRedirect('/settings/security');
        $this->get('/settings/security')->assertRedirectContains('password'); // allowed through (password confirmation first)
        $this->officer->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->get('/dashboard')->assertOk();
    }
}
