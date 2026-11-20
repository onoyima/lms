<?php

namespace Tests\Feature\Exams;

use App\Models\CertificateTemplate;
use App\Models\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Certificates and badges: awarded when results are released to those who reach the pass mark; checkable; revocable. */
class CertificatesTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_certificates_are_awarded_on_release_checked_and_revoked(): void
    {
        $this->actingAs($this->officer)->post('/certificates', ['name' => 'CHM pass', 'kind' => 'certificate', 'heading' => 'Certificate of achievement',
            'body' => 'has passed {exam} with {score} (grade {grade}) on {date}.', 'signatory_name' => 'Prof. A. Bello', 'signatory_title' => 'Registrar',
            'colour' => '#1d3557', 'badge_icon' => 'award', 'min_percent' => 50, 'auto_award' => true])->assertSessionHasNoErrors();
        $t = CertificateTemplate::sole();
        $exam = $this->cbtExam(['total_mark' => 100, 'status' => 'closed']);
        $this->put("/exams/{$exam->id}", ['certificate_template_id' => $t->id])->assertSessionHasNoErrors();
        $this->assertSame($t->id, $exam->fresh()->certificate_template_id);

        $pass = User::factory()->create(['user_type' => 'student', 'name' => 'Ada Okafor']);
        $fail = User::factory()->create(['user_type' => 'student']);
        Result::create(['exam_id' => $exam->id, 'user_id' => $pass->id, 'raw_score' => 72, 'max_score' => 100, 'final_score' => 72, 'grade' => 'A', 'status' => 'approved']);
        Result::create(['exam_id' => $exam->id, 'user_id' => $fail->id, 'raw_score' => 31, 'max_score' => 100, 'final_score' => 31, 'grade' => 'F', 'status' => 'approved']);

        // releasing results awards it to whoever reached 50%
        app(\App\Services\Exams\ExamWorkflow::class)->moveResults($exam->fresh(), 'published', $this->officer);
        $this->assertSame([$pass->id], DB::table('certificate_awards')->pluck('user_id')->all());
        $code = DB::table('certificate_awards')->value('code');

        $this->actingAs($pass)->get('/my/certificates')->assertInertia(fn ($p) => $p->where('awards.0.code', $code)->where('awards.0.grade', 'A'));
        $pdf = $this->get("/certificates/{$code}.pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->actingAs($fail)->get("/certificates/{$code}.pdf")->assertNotFound();
        $this->get("/verify/{$code}")->assertInertia(fn ($p) => $p->where('doc.type', 'certificate')->where('doc.revoked', false)->where('doc.holder', 'Ada Okafor'));

        // awarding again gives nobody a second one
        $this->actingAs($this->officer)->post("/exams/{$exam->id}/certificates")->assertSessionHas('success', fn ($m) => str_contains($m, 'Nobody new'));
        $this->post('/certificates/awards/'.DB::table('certificate_awards')->value('id').'/revoke', ['reason' => 'Malpractice found'])->assertSessionHasNoErrors();
        $this->get("/verify/{$code}")->assertInertia(fn ($p) => $p->where('doc.revoked', true));
        $this->actingAs($pass)->get('/my/certificates')->assertInertia(fn ($p) => $p->has('awards', 0));
    }
}
