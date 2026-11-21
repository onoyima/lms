<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\Question;
use App\Models\QuestionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Students report a faulty question from the exam screen; examiners settle it for everyone. */
class QuestionReportsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    /** Two students: one picks option A, one picks option B, on a question whose key says B. */
    private function twoScripts(): array
    {
        $q = Question::create(['type' => 'single_choice', 'stem_html' => '<p>Capital of Nigeria?</p>', 'mark' => 2, 'status' => 'approved']);
        $a = $q->options()->create(['label' => 'A', 'body_html' => 'Abuja', 'is_correct' => false, 'position' => 0]);
        $b = $q->options()->create(['label' => 'B', 'body_html' => 'Lagos', 'is_correct' => true, 'position' => 1]);
        $other = Question::create(['type' => 'single_choice', 'stem_html' => '<p>2 + 2?</p>', 'mark' => 1, 'status' => 'approved']);
        $four = $other->options()->create(['label' => 'A', 'body_html' => '4', 'is_correct' => true, 'position' => 0]);
        $exam = $this->cbtExam(['total_mark' => 3], [$q, $other]);
        $session = $this->sitting($exam);
        $tokens = [];
        foreach ([$a, $b] as $pick) {
            $token = $this->startAs($this->candidate($session))->json('token');
            $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q->id, 'seq' => 1, 'response' => ['options' => [$pick->id]]], ['question_id' => $other->id, 'seq' => 2, 'response' => ['options' => [$four->id]]]]]);
            $tokens[] = $token;
        }

        return [$exam, $q, $a, $b, $tokens];
    }

    public function test_a_student_reports_a_question_and_the_examiners_correct_the_answer(): void
    {
        [$exam, $q, $a, , $tokens] = $this->twoScripts();
        $this->api($tokens[0], 'POST', 'report', ['question_id' => $q->id, 'reason' => 'wrong_key', 'note' => 'Abuja is the capital, not Lagos'])->assertCreated();
        // a second report from the same student replaces the first; a question not on the paper is refused
        $this->api($tokens[0], 'POST', 'report', ['question_id' => $q->id, 'reason' => 'wrong_key', 'note' => 'Abuja since 1991'])->assertCreated();
        $this->api($tokens[0], 'POST', 'report', ['question_id' => 999999, 'reason' => 'unclear'])->assertStatus(422);
        $this->api($tokens[0], 'POST', 'report', ['question_id' => $q->id, 'reason' => 'nonsense'])->assertStatus(422);
        $this->api($tokens[1], 'POST', 'report', ['question_id' => $q->id, 'reason' => 'two_answers'])->assertCreated();
        $this->assertSame(2, QuestionReport::count());
        foreach ($tokens as $t) {
            $this->api($t, 'POST', 'submit')->assertOk();
        }
        $this->assertSame([1.0, 3.0], Attempt::orderBy('id')->pluck('score_raw')->map(fn ($v) => (float) $v)->all());

        $this->actingAs($this->officer)->get("/exams/{$exam->id}")->assertInertia(fn ($p) => $p->where('questionReports.0.count', 2)->where('questionReports.0.open', 2)
            ->where('questionReports.0.reasons.wrong_key', 1)->where('questionReports.0.notes.0.note', 'Abuja since 1991'));

        $this->post("/exams/{$exam->id}/question-reports/{$q->id}", ['action' => 'fix_key', 'correct' => [$a->id], 'response' => 'Abuja is right.'])
            ->assertSessionHas('success', 'Answer corrected. 2 scripts marked again.');
        $this->assertSame([3.0, 1.0], Attempt::orderBy('id')->pluck('score_raw')->map(fn ($v) => (float) $v)->all());
        $this->assertTrue((bool) $a->fresh()->is_correct); // later papers are right too
        $this->assertSame(['resolved'], QuestionReport::distinct()->pluck('status')->all());
    }

    public function test_full_marks_or_taking_the_question_out_for_everyone(): void
    {
        [$exam, $q, , , $tokens] = $this->twoScripts();
        $this->api($tokens[0], 'POST', 'report', ['question_id' => $q->id, 'reason' => 'no_answer'])->assertCreated();
        foreach ($tokens as $t) {
            $this->api($t, 'POST', 'submit');
        }
        $this->actingAs($this->officer)->post("/exams/{$exam->id}/question-reports/{$q->id}", ['action' => 'full_mark'])->assertSessionHasNoErrors();
        $this->assertSame([3.0, 3.0], Attempt::orderBy('id')->pluck('score_raw')->map(fn ($v) => (float) $v)->all());

        QuestionReport::query()->update(['status' => 'open']);
        $this->post("/exams/{$exam->id}/question-reports/{$q->id}", ['action' => 'drop']);
        $this->assertSame([[1.0, 1.0], [1.0, 1.0]], Attempt::orderBy('id')->get()->map(fn ($a) => [(float) $a->score_raw, (float) $a->score_max])->all());

        // once results are approved, scores change only through result changes
        \App\Models\Result::where('exam_id', $exam->id)->update(['locked' => true]);
        $this->post("/exams/{$exam->id}/question-reports/{$q->id}", ['action' => 'full_mark'])->assertStatus(422);
        $this->post("/exams/{$exam->id}/question-reports/{$q->id}", ['action' => 'dismiss'])->assertSessionHas('success');
    }
}
