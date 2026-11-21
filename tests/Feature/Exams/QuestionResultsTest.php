<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\Question;
use App\Models\ResultChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Statistics per question, the Q1…Qn score sheet, scripts, and correcting one answer. */
class QuestionResultsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    private function written(): array
    {
        $q1 = Question::create(['type' => 'single_choice', 'stem_html' => '<p>Capital of Nigeria?</p>', 'mark' => 2, 'status' => 'approved']);
        $abuja = $q1->options()->create(['label' => 'A', 'body_html' => 'Abuja', 'is_correct' => true, 'position' => 0]);
        $lagos = $q1->options()->create(['label' => 'B', 'body_html' => 'Lagos', 'is_correct' => false, 'position' => 1]);
        $q2 = Question::create(['type' => 'multi_true_false', 'stem_html' => '<p>The heart</p>', 'mark' => 2, 'status' => 'approved']);
        $s1 = $q2->options()->create(['label' => 'A', 'body_html' => 'Four chambers', 'is_correct' => true, 'position' => 0]);
        $s2 = $q2->options()->create(['label' => 'B', 'body_html' => 'Pumps air', 'is_correct' => false, 'position' => 1]);
        $exam = $this->cbtExam(['total_mark' => 4], [$q1, $q2]);
        $session = $this->sitting($exam);
        $people = [['Ada Chi Obi', $abuja, [$s1->id => true, $s2->id => false]], ['Bayo Ade', $lagos, [$s1->id => true, $s2->id => true]], ['Chika Eze', $abuja, []]];
        foreach ($people as [$name, $pick, $tf]) {
            $u = User::factory()->create(['user_type' => 'student', 'name' => $name, 'identifier' => 'VUG/'.substr($name, 0, 2)]);
            $token = $this->startAs($this->candidate($session, $u))->json('token');
            $answers = [['question_id' => $q1->id, 'seq' => 1, 'response' => ['options' => [$pick->id]]]];
            $tf && $answers[] = ['question_id' => $q2->id, 'seq' => 2, 'response' => ['tf' => $tf]];
            $this->api($token, 'POST', 'answers', ['answers' => $answers]);
            $this->api($token, 'POST', 'submit')->assertOk();
        }

        return [$exam, $q1, $q2, $abuja, $lagos];
    }

    public function test_statistics_per_question_like_the_old_system(): void
    {
        [$exam, $q1, $q2] = $this->written();
        $this->actingAs($this->officer)->get("/results/{$exam->id}/questions")->assertOk()->assertInertia(fn ($p) => $p->component('results/questions')
            ->where('questions.0.n', 1)->where('questions.0.seen', 3)->where('questions.0.answered', 3)->where('questions.0.full_marks', 2)
            ->where('questions.0.average', 1.33)->where('questions.0.facility', 66.7)
            ->where('questions.0.options.0.count', 2)->where('questions.0.options.0.correct', true)->where('questions.0.options.1.percent', 33.3)
            ->where('questions.1.answered', 2)->where('questions.1.statements.0.percent', 66.7)->where('questions.1.statements.1.percent', 33.3)
            ->has('scripts', 3));
        $this->get("/results/{$exam->id}/questions/export?format=csv")->assertOk();
    }

    public function test_the_score_sheet_has_names_split_and_q1_to_qn(): void
    {
        [$exam] = $this->written();
        $csv = $this->actingAs($this->officer)->get("/results/{$exam->id}/score-sheet.csv")->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv)))));
        $this->assertSame(['First Name', 'Middle Name', 'Last Name', 'Username', 'Matric', 'Q1', 'Q2 (MTF)', 'Total', 'Out of', 'Percentage', 'Final score', 'Grade', 'Time taken (min)', 'Status'], $lines[0]);
        $this->assertSame(['Ada', 'Chi', 'Obi'], array_slice($lines[1], 0, 3));
        $this->assertSame(['2', '2', '4', '4'], [$lines[1][5], $lines[1][6], $lines[1][7], $lines[1][8]]);
        $this->assertSame('0', $lines[2][5]); // Bayo chose Lagos
        $this->get("/results/{$exam->id}/score-sheet.xlsx")->assertOk();
    }

    public function test_correcting_one_answer_needs_a_second_officer_then_marks_again(): void
    {
        [$exam, $q1, , $abuja] = $this->written();
        $bayo = Attempt::whereHas('user', fn ($u) => $u->where('name', 'Bayo Ade'))->sole();
        $this->actingAs($this->officer)->get("/results/{$exam->id}/scripts/{$bayo->id}")->assertOk()->assertInertia(fn ($p) => $p->component('results/script')
            ->where('items.0.options.1.chosen', true)->where('items.0.options.0.right', true)->where('items.0.score', 0)->where('needsApproval', true));

        $this->post("/results/{$exam->id}/scripts/{$bayo->id}/answers/{$q1->id}", ['options' => [$abuja->id], 'reason' => 'Short'])->assertSessionHasErrors('reason');
        $this->post("/results/{$exam->id}/scripts/{$bayo->id}/answers/{$q1->id}", ['options' => [$abuja->id], 'reason' => 'His answer sheet shows A; the click did not register.'])->assertSessionHas('success');
        $change = ResultChange::sole();
        $this->assertSame('pending', $change->status);
        $this->assertSame(1.0, (float) $bayo->fresh()->score_raw);
        $this->get("/results/{$exam->id}")->assertInertia(fn ($p) => $p->where('changes.0.answer.number', 1));

        // the same officer cannot approve their own correction; a second one can
        $this->post("/results/changes/{$change->id}", ['approve' => true])->assertForbidden();
        $second = User::factory()->create(['user_type' => 'staff']);
        $second->assignRole('institution_admin');
        $this->actingAs($second)->post("/results/changes/{$change->id}", ['approve' => true])->assertRedirect();
        $this->assertSame(3.0, (float) $bayo->fresh()->score_raw);
        $this->assertSame(3.0, (float) $change->fresh()->to_score);
    }

    public function test_without_result_approval_an_approver_corrects_at_once(): void
    {
        [$exam, $q1, , $abuja] = $this->written();
        $this->tenant->update(['settings' => array_replace_recursive((array) $this->tenant->settings, ['policy' => ['result_change_approval' => false]])]);
        $bayo = Attempt::whereHas('user', fn ($u) => $u->where('name', 'Bayo Ade'))->sole();
        $this->actingAs($this->officer)->post("/results/{$exam->id}/scripts/{$bayo->id}/answers/{$q1->id}", ['options' => [$abuja->id], 'reason' => 'Answer sheet shows A clearly.'])
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Answer corrected'));
        $this->assertSame(3.0, (float) $bayo->fresh()->score_raw);
    }
}
