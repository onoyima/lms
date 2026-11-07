<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\Exam;
use App\Models\Result;
use App\Models\ResultChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkingAndResultsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_essay_marking_completes_the_result(): void
    {
        $exam = $this->cbtExam();
        $c = $this->candidate($this->sitting($exam));
        $token = $this->startAs($c)->json('token');
        $essay = collect($this->api($token, 'GET', 'paper')->json('paper.sections.0.questions'))->firstWhere('type', 'essay');
        $this->api($token, 'POST', 'submit', ['answers' => [['question_id' => $essay['id'], 'seq' => 1, 'response' => ['text' => 'Osmosis is...']]]]);

        $this->actingAs($this->officer)->get("/marking/{$exam->id}/essays")->assertOk()
            ->assertInertia(fn ($p) => $p->has('answers', 1)->where('answers.0.text', 'Osmosis is...')->missing('answers.0.name'));

        $this->actingAs($this->officer)->post("/marking/{$exam->id}/essays", ['attempt_id' => Attempt::first()->id, 'question_id' => $essay['id'], 'score' => 4, 'feedback' => 'Good'])->assertRedirect();
        $this->actingAs($this->officer)->post("/marking/{$exam->id}/essays", ['attempt_id' => Attempt::first()->id, 'question_id' => $essay['id'], 'score' => 9])->assertStatus(422);

        $attempt = Attempt::first();
        $this->assertFalse($attempt->needs_marking);
        $this->assertSame(4.0, $attempt->score_raw);
        $this->assertSame('draft', Result::first()->status);
    }

    public function test_practical_checklist_with_allowed_scores_and_two_examiners(): void
    {
        $exam = Exam::create(['title' => 'Blood transfusion practical', 'type' => 'practical', 'status' => 'draft', 'total_mark' => 20, 'marking_policy' => 'average', 'created_by' => $this->officer->id]);
        $s = $this->sitting($exam);
        $c = $this->candidate($s);

        $this->actingAs($this->officer)->put("/exams/{$exam->id}/criteria", ['criteria' => [
            ['text' => 'Greets patient and introduces self', 'allowed_scores' => '0,0.25'],
            ['text' => 'Checks pre-transfusion vital signs', 'allowed_scores' => '0, 0.5, 1'],
            ['text' => 'Watches for reaction signs', 'allowed_scores' => '0,0.75,1.5'],
        ]])->assertRedirect();
        $criteria = $exam->criteria()->get();
        $this->assertEquals([0, 0.5, 1], $criteria[1]->allowed_scores);
        $exam->update(['status' => 'scheduled']);

        $e2 = User::factory()->create();
        $e2->assignRole('lecturer');
        $exam->examiners()->attach([$this->officer->id => ['role' => 'examiner'], $e2->id => ['role' => 'examiner']]);

        $this->actingAs($this->officer)->post("/marking/{$exam->id}/panel", ['user_id' => $c->user_id, 'scores' => [
            $criteria[0]->id => 0.25, $criteria[1]->id => 1, $criteria[2]->id => 1.5,
        ]])->assertRedirect();
        // a value not on the checklist is refused
        $this->actingAs($e2)->post("/marking/{$exam->id}/panel", ['user_id' => $c->user_id, 'scores' => [$criteria[1]->id => 0.7]])->assertStatus(422);
        $this->actingAs($e2)->post("/marking/{$exam->id}/panel", ['user_id' => $c->user_id, 'scores' => [
            $criteria[0]->id => 0.25, $criteria[1]->id => 0.5, $criteria[2]->id => 0.75,
        ]])->assertRedirect();

        $r = Result::first();
        $this->assertSame(2.13, $r->raw_score); // averages per criterion: .25 + .75 + 1.125 = 2.125
        $this->assertSame(2.75, $r->max_score);
        $this->assertSame(round(2.13 / 2.75 * 20, 2), $r->final_score);
    }

    public function test_viva_single_score_out_of_total(): void
    {
        $this->actingAs($this->officer)->post('/exams', ['title' => 'Final year viva', 'type' => 'viva', 'total_mark' => 40])->assertRedirect();
        $exam = Exam::where('type', 'viva')->first();
        $criterion = $exam->criteria()->first();
        $this->assertSame(40.0, $criterion->max_score);
        $exam->update(['status' => 'scheduled']);
        $c = $this->candidate($this->sitting($exam));
        $exam->examiners()->attach($this->officer->id);

        $this->actingAs($this->officer)->post("/marking/{$exam->id}/panel", ['user_id' => $c->user_id, 'scores' => [$criterion->id => 33.5]])->assertRedirect();
        $this->actingAs($this->officer)->post("/marking/{$exam->id}/panel", ['user_id' => $c->user_id, 'scores' => [$criterion->id => 41]])->assertStatus(422);

        $this->assertSame(33.5, Result::first()->final_score);
        // panel exams are not taken on screen
        $this->startAs($c)->assertJsonPath('reason', 'not_on_screen');
    }

    public function test_results_move_through_moderation_approval_publication_and_lock(): void
    {
        $exam = $this->cbtExam([], [$this->questions()[0]]);
        $c = $this->candidate($this->sitting($exam));
        $this->api($this->startAs($c)->json('token'), 'POST', 'submit');
        $result = Result::first();

        $this->actingAs($this->officer)->post("/results/{$exam->id}/transition", ['to' => 'published'])->assertRedirect();
        $this->assertSame('draft', $result->fresh()->status); // cannot skip steps

        foreach (['moderated', 'approved', 'published'] as $to) {
            $this->actingAs($this->officer)->post("/results/{$exam->id}/transition", ['to' => $to])->assertRedirect();
        }
        $result->refresh();
        $this->assertSame('published', $result->status);
        $this->assertTrue($result->locked);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $c->user_id]);

        // student sees it
        $this->actingAs($c->user)->get('/my/results')->assertInertia(fn ($p) => $p->has('results', 1));
    }

    public function test_changing_an_approved_result_needs_a_second_officer(): void
    {
        $exam = $this->cbtExam([], [$this->questions()[0]]);
        $c = $this->candidate($this->sitting($exam));
        $this->api($this->startAs($c)->json('token'), 'POST', 'submit');
        foreach (['moderated', 'approved'] as $to) {
            $this->actingAs($this->officer)->post("/results/{$exam->id}/transition", ['to' => $to]);
        }
        $result = Result::first();

        $this->actingAs($this->officer)->post("/results/items/{$result->id}/change", ['to' => 55, 'reason' => 'Script re-marked after appeal'])->assertRedirect();
        $change = ResultChange::first();
        $this->actingAs($this->officer)->post("/results/changes/{$change->id}", ['approve' => true])->assertForbidden();

        $dean = User::factory()->create();
        $dean->assignRole('dean');
        $this->actingAs($dean)->post("/results/changes/{$change->id}", ['approve' => true])->assertRedirect();

        $this->assertSame(55.0, $result->fresh()->final_score);
        $this->assertSame(2, $result->fresh()->version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'result.change_approved']);
    }

    public function test_paper_approval_requires_someone_other_than_the_setter(): void
    {
        $setter = User::factory()->create();
        $setter->assignRole('institution_admin');
        $exam = $this->cbtExam(['status' => 'draft', 'created_by' => $setter->id]);

        $this->actingAs($setter)->post("/exams/{$exam->id}/transition", ['action' => 'submit'])->assertRedirect();
        $this->actingAs($setter)->post("/exams/{$exam->id}/transition", ['action' => 'approve'])->assertForbidden();
        $this->actingAs($this->officer)->post("/exams/{$exam->id}/transition", ['action' => 'approve'])->assertRedirect();

        $this->assertSame('scheduled', $exam->fresh()->status);
        $this->assertDatabaseHas('exam_versions', ['exam_id' => $exam->id, 'version' => 1]);
    }

    public function test_item_analysis_and_suspicious_pairs(): void
    {
        $q = $this->questions();
        $exam = $this->cbtExam(['max_attempts' => 1], [$q[0], $q[1]]);
        $s = $this->sitting($exam);
        foreach (range(1, 8) as $i) {
            $c = $this->candidate($s);
            $token = $this->startAs($c)->json('token');
            $paper = collect($this->api($token, 'GET', 'paper')->json('paper.sections.0.questions'));
            $q1 = $paper->firstWhere('type', 'single_choice');
            $pick = $i <= 5 ? collect($q1['options'])->firstWhere('html', '2')['id'] : collect($q1['options'])->firstWhere('html', '3')['id'];
            $this->api($token, 'POST', 'submit', ['answers' => [['question_id' => $q1['id'], 'seq' => 1, 'response' => ['options' => [$pick]]]]]);
        }

        $items = app(\App\Services\Analytics\ExamAnalytics::class)->itemAnalysis($exam);
        $stat = collect($items)->firstWhere('question_id', $q[0]->id);
        $this->assertSame(0.625, (float) $stat['difficulty']);
        $pairs = app(\App\Services\Analytics\ExamAnalytics::class)->suspiciousPairs($s, 1);
        $this->assertCount(3, $pairs); // the three who picked the same wrong option
        $this->actingAs($this->officer)->get("/results/{$exam->id}/analytics")->assertOk();
    }

    public function test_results_export_is_permission_checked_and_audited(): void
    {
        $exam = $this->cbtExam();
        $student = User::factory()->create();
        $student->assignRole('student');
        $this->actingAs($student)->get("/results/{$exam->id}/export")->assertForbidden();
        $this->actingAs($this->officer)->get("/results/{$exam->id}/export")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'results.exported']);
    }
}
