<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseGrade;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\Semester;
use App\Models\User;
use App\Services\Documents\AcademicRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class DocumentsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    public function test_transcript_gpa_pdf_and_public_verification(): void
    {
        $this->setUpPlatform();
        $s = User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/1']);
        $other = User::factory()->create(['user_type' => 'student']);
        $session = AcademicSession::create(['name' => '2026/2027']);
        $sem = Semester::create(['academic_session_id' => $session->id, 'name' => 'First', 'is_current' => true]);
        foreach ([['CHM 101', 3, 'A', 75], ['MTH 101', 2, 'C', 52], ['GST 101', 1, 'F', 30]] as [$code, $units, $grade, $total]) {
            $o = CourseOffering::create(['course_id' => Course::create(['code' => $code, 'title' => $code, 'credit_units' => $units])->id, 'semester_id' => $sem->id, 'status' => 'open']);
            Enrolment::create(['course_offering_id' => $o->id, 'user_id' => $s->id, 'status' => 'active']);
            CourseGrade::create(['course_offering_id' => $o->id, 'user_id' => $s->id, 'total' => $total, 'grade' => $grade, 'status' => 'released']);
        }
        // (3×5 + 2×3 + 1×0) / 6 = 3.5
        $this->assertSame(3.5, app(AcademicRecord::class)->record($s)['cgpa']);

        $pdf = $this->actingAs($s)->get('/my/documents/transcript')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $code = DB::table('issued_documents')->where('user_id', $s->id)->value('code');
        $this->actingAs($s)->get('/my/documents/registration-slip')->assertOk();

        auth()->logout();
        $this->get("/verify/$code")->assertOk()->assertSee($s->name);
        $this->assertNull($this->get('/verify/AAAAAAAAAAAAAAAA')->assertOk()->viewData('page')['props']['doc']);

        // Students cannot pull someone else's transcript; Registry can
        $this->actingAs($other)->get("/admin/students/{$s->id}/transcript")->assertForbidden();
        $this->actingAs($this->officer)->get("/admin/students/{$s->id}/transcript")->assertOk();
    }
}
