<?php

namespace Tests\Feature\Exams;

use App\Models\Result;
use App\Support\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** An exam can use one of the institution's other grading scales; results also download as JSON. */
class GradingScalesAndJsonTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_an_exam_uses_its_own_grading_scale_and_results_export_as_json(): void
    {
        $policy = $this->actingAs($this->officer)->get('/admin/policy')->viewData('page')['props']['policy'];
        $policy['grading_scales'] = ['Postgraduate' => ['grades' => ['A' => 70, 'B' => 60, 'C' => 50, 'F' => 0], 'points' => ['A' => 4, 'B' => 3, 'C' => 2, 'F' => 0], 'pass' => 'C']];
        $this->put('/admin/policy', $policy)->assertSessionHasNoErrors();
        $bad = $policy;
        $bad['grading_scales']['Broken'] = ['grades' => ['P' => 50, 'X' => 10], 'pass' => 'P'];
        $this->put('/admin/policy', $bad)->assertStatus(422); // lowest grade must start at 0

        $exam = $this->cbtExam(['total_mark' => 100]);
        $this->assertContains('Postgraduate', $this->get("/exams/{$exam->id}")->viewData('page')['props']['exam']['grading_scales']);
        $this->put("/exams/{$exam->id}", ['settings' => array_replace((array) $exam->settings, ['grading_scale' => 'Postgraduate'])])->assertSessionHasNoErrors();
        $exam->refresh();

        // 55% is a D on the main (5-point) scale but a C on the postgraduate one
        $this->assertSame('D', Policy::grade(46));
        $c = $this->candidate($this->sitting($exam));
        $result = app(\App\Services\Exams\Marker::class)->refreshResult($exam, $c->user_id) ?? Result::create(['exam_id' => $exam->id, 'user_id' => $c->user_id, 'raw_score' => 55, 'max_score' => 100, 'final_score' => 55, 'status' => 'draft']);
        $result->forceFill(['final_score' => 55, 'grade' => app(\App\Services\Exams\Marker::class)->grade(55, 'Postgraduate')])->save();
        $this->assertSame('C', $result->grade);

        $json = json_decode($this->get("/results/{$exam->id}/export?format=json")->assertOk()->streamedContent(), true);
        $this->assertSame('C', $json['grading_scale']['pass']);
        $this->assertSame('C', $json['results'][0]['grade']);
        $this->assertEquals(2, $json['results'][0]['grade_point']);
        $this->assertSame($c->user->identifier, $json['results'][0]['matric_number']);
    }
}
