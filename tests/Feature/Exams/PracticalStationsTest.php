<?php

namespace Tests\Feature\Exams;

use App\Models\CriterionMark;
use App\Models\PracticalEvidence;
use App\Models\Result;
use App\Support\Exams\PracticalTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Practical exams for any discipline: stations, lines marked on a scale, as done / not done, or from a
 * reading against the expected value; critical steps; work handed in by candidates; templates.
 */
class PracticalStationsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        Storage::fake('local');
    }

    private function practical(): \App\Models\Exam
    {
        $exam = $this->cbtExam(['type' => 'practical', 'status' => 'draft', 'title' => 'CHM 201 Titration practical']);
        $this->actingAs($this->officer)->put("/exams/{$exam->id}/criteria", [
            'criteria' => [
                ['station' => 'Safety', 'text' => 'Wears goggles throughout', 'kind' => 'check', 'max_score' => 1, 'critical' => true],
                ['station' => 'Titration', 'text' => 'Reads the meniscus at eye level', 'kind' => 'check', 'max_score' => 1],
                ['station' => 'Titration', 'text' => 'Accurate titre', 'kind' => 'measure', 'max_score' => 6, 'expected' => 24.5, 'tolerance' => 0.1, 'unit' => 'cm³'],
                ['station' => 'Report', 'text' => 'Calculation', 'kind' => 'score', 'allowed_scores' => '0,1,2,3,4', 'guide' => 'Equation, moles, concentration'],
            ],
            'stations' => [['name' => 'Safety', 'minutes' => 0], ['name' => 'Titration', 'minutes' => 45], ['name' => 'Report', 'minutes' => 30]],
            'evidence' => true, 'evidence_types' => ['pdf', 'jpg'], 'discipline' => 'chemistry',
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_every_discipline_has_a_template_with_stations(): void
    {
        foreach (['nursing', 'clinical', 'chemistry', 'physics', 'hardware', 'software', 'biology', 'pharmacy', 'workshop'] as $key) {
            $t = PracticalTemplates::all()[$key];
            $this->assertNotEmpty($t['stations'], $key);
            foreach ($t['stations'] as $st) {
                foreach ($st['criteria'] as $c) {
                    $this->assertContains($c['kind'], ['score', 'check', 'measure']);
                    if ($c['kind'] === 'measure') {
                        $this->assertArrayHasKey('expected', $c);
                    }
                }
            }
        }
        $exam = $this->cbtExam(['type' => 'practical', 'status' => 'draft']);
        $this->actingAs($this->officer)->get("/exams/{$exam->id}")->assertInertia(fn ($p) => $p->has('practical.templates.hardware')->has('practical.templates.software'));
    }

    public function test_stations_readings_and_a_failed_critical_step(): void
    {
        $exam = $this->practical();
        $this->assertSame(45, $exam->settings['practical']['stations'][1]['minutes']);
        $c = $exam->criteria->keyBy('text');
        $this->assertSame('measure', $c['Accurate titre']->kind);
        $cand = $this->candidate($this->sitting($exam, ['mode' => 'hall']));
        $exam->examiners()->attach($this->officer->id, ['role' => 'examiner']);

        // titre 24.65: within twice the tolerance, so half of 6; safety not done zeroes the Safety station only
        $this->actingAs($this->officer)->post("/marking/{$exam->id}/panel", ['user_id' => $cand->user_id, 'scores' => [
            $c['Wears goggles throughout']->id => ['score' => 0],
            $c['Reads the meniscus at eye level']->id => ['score' => 1],
            $c['Accurate titre']->id => ['reading' => 24.65, 'comment' => 'slightly over'],
            $c['Calculation']->id => ['score' => 3],
        ]])->assertSessionHasNoErrors();

        $this->assertEquals(3.0, CriterionMark::where('marking_criterion_id', $c['Accurate titre']->id)->value('score'));
        $this->assertEquals(24.65, CriterionMark::where('marking_criterion_id', $c['Accurate titre']->id)->value('reading'));
        $panel = app(\App\Services\Exams\Marker::class)->panelScore($exam, $cand->user_id);
        $this->assertEquals(7.0, $panel['raw']);          // 0 (Safety) + 1 + 3 (Titration) + 3 (Report)
        $this->assertSame(['Safety'], $panel['failed_stations']);
        $this->assertEquals(7.0, (float) Result::where('exam_id', $exam->id)->where('user_id', $cand->user_id)->value('raw_score'));

        // done / not done accepts only 0 or the full mark
        $this->post("/marking/{$exam->id}/panel", ['user_id' => $cand->user_id, 'scores' => [$c['Reads the meniscus at eye level']->id => ['score' => 0.5]]])->assertStatus(422);

        $this->get("/marking/{$exam->id}/panel")->assertInertia(fn ($p) => $p->where('criteria.2.kind', 'measure')->where('stations.1.name', 'Titration')
            ->where('candidates.0.my_scores.'.$c['Accurate titre']->id.'.reading', 24.65));
    }

    public function test_candidates_hand_in_work_and_examiners_see_it(): void
    {
        $exam = $this->practical();
        $exam->update(['status' => 'scheduled']);
        $cand = $this->candidate($this->sitting($exam, ['mode' => 'hall']));
        $other = $this->candidate($this->sitting($exam, ['mode' => 'hall']));
        $exam->examiners()->attach($this->officer->id, ['role' => 'examiner']);

        $this->actingAs($cand->user)->get('/my/exams')->assertInertia(fn ($p) => $p->where('practicals.0.evidence', true)->where('practicals.0.can_hand_in', true));
        $this->post("/my/practicals/{$cand->id}/evidence", ['file' => UploadedFile::fake()->create('report.pdf', 40, 'application/pdf')])->assertSessionHasNoErrors();
        $this->post("/my/practicals/{$cand->id}/evidence", ['file' => UploadedFile::fake()->create('notes.docx', 4)])->assertStatus(422);
        $e = PracticalEvidence::firstOrFail();

        // only the candidate, the examiners and the exams office open it
        $this->actingAs($other->user)->get("/my/practicals/evidence/{$e->id}")->assertForbidden();
        $this->actingAs($this->officer)->get("/my/practicals/evidence/{$e->id}")->assertOk();
        $this->post("/marking/{$exam->id}/evidence", ['user_id' => $cand->user_id, 'file' => UploadedFile::fake()->image('bench.jpg')])->assertSessionHasNoErrors();
        $this->get("/marking/{$exam->id}/panel")->assertInertia(fn ($p) => $p->where('evidenceOn', true)->has('candidates.0.evidence', 2));

        // after the practical (plus two hours) handing in closes
        $this->travel(5)->hours();
        $this->actingAs($cand->user)->post("/my/practicals/{$cand->id}/evidence", ['file' => UploadedFile::fake()->create('late.pdf', 4, 'application/pdf')])->assertStatus(422);
    }

    public function test_saving_general_settings_keeps_the_practical_set_up(): void
    {
        $exam = $this->practical();
        $this->actingAs($this->officer)->put("/exams/{$exam->id}", ['title' => $exam->title, 'type' => 'practical', 'duration_minutes' => 60, 'total_mark' => 100, 'max_attempts' => 1,
            'settings' => ['shuffle_questions' => false]]);
        $this->assertTrue($exam->fresh()->settings['practical']['evidence']);
    }
}
