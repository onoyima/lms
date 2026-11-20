<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Multiple true/false: several statements, each true or false, scored statement by statement. */
class MultipleTrueFalseTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    private function mtf(): Question
    {
        $this->actingAs($this->officer)->post('/question-bank/questions', [
            'type' => 'multi_true_false', 'stem_html' => '<p>Concerning the heart:</p>', 'mark' => 4,
            'options' => [['html' => 'Four chambers', 'correct' => true], ['html' => 'Left ventricle pumps to the lungs', 'correct' => false],
                ['html' => 'SA node is the pacemaker', 'correct' => true], ['html' => 'Valves stop backflow', 'correct' => true]],
        ])->assertSessionHasNoErrors();
        $q = Question::where('type', 'multi_true_false')->sole();
        $q->update(['status' => 'approved']);

        return $q;
    }

    public function test_a_setter_saves_one_and_each_statement_earns_its_share(): void
    {
        $q = $this->mtf();
        $this->assertSame([true, false, true, true], $q->options()->orderBy('position')->pluck('is_correct')->map(fn ($v) => (bool) $v)->all());

        $exam = $this->cbtExam(['total_mark' => 4], [$q]);
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        $paper = $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions.0');
        $this->assertSame('multi_true_false', $paper['type']);
        $this->assertCount(4, $paper['options']);
        $this->assertStringNotContainsString('correct', json_encode($paper));
        [$a, $b, $c, $d] = array_column($paper['options'], 'id');

        // a, b right; c wrong; d left blank → 2 of 4 statements = 2 marks
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q->id, 'seq' => 1, 'response' => ['tf' => [$a => true, $b => false, $c => false]]]]])
            ->assertOk()->assertJsonPath('rejected', []);
        // a statement that is not in the question, or a value that is not true/false, is refused
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q->id, 'seq' => 2, 'response' => ['tf' => [999999 => true]]]]])->assertJsonPath('rejected', [$q->id]);
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q->id, 'seq' => 3, 'response' => ['tf' => [$a => 'yes']]]]])->assertJsonPath('rejected', [$q->id]);

        $this->api($token, 'POST', 'submit')->assertOk();
        $this->assertSame(2.0, (float) Attempt::first()->score_raw);
    }

    public function test_negative_marking_and_all_or_nothing(): void
    {
        $q = $this->mtf();
        $exam = $this->cbtExam(['total_mark' => 4, 'settings' => ['shuffle_questions' => false, 'shuffle_options' => false, 'require_check_in' => false, 'negative_marking' => 1]], [$q]);
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        [$a, $b, $c, $d] = array_column($this->api($token, 'GET', 'paper')->json('paper.sections.0.questions.0.options'), 'id');
        // 3 right, 1 wrong: 4×3/4 − 1×1/4 = 2.75
        $this->api($token, 'POST', 'submit', ['answers' => [['question_id' => $q->id, 'seq' => 1, 'response' => ['tf' => [$a => true, $b => false, $c => true, $d => false]]]]])->assertOk();
        $this->assertSame(2.75, (float) Attempt::first()->score_raw);

        $strict = $this->cbtExam(['total_mark' => 4, 'settings' => ['shuffle_questions' => false, 'shuffle_options' => false, 'require_check_in' => false, 'mtf_scoring' => 'all_or_nothing']], [$q]);
        $c2 = $this->candidate($this->sitting($strict));
        $t2 = $this->startAs($c2)->json('token');
        $ids = array_column($this->api($t2, 'GET', 'paper')->json('paper.sections.0.questions.0.options'), 'id');
        $this->api($t2, 'POST', 'submit', ['answers' => [['question_id' => $q->id, 'seq' => 1, 'response' => ['tf' => [$ids[0] => true, $ids[1] => false, $ids[2] => true, $ids[3] => true]]]]])->assertOk();
        $this->assertSame(4.0, (float) Attempt::where('exam_id', $strict->id)->sole()->score_raw);
    }
}
