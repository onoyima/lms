<?php

namespace Tests\Feature\Exams;

use App\Models\Question;
use App\Models\User;
use App\Services\Questions\Import\DocumentConverter;
use App\Services\Questions\Import\QuestionParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionBankTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private function setter(): User
    {
        $u = User::factory()->create();
        $u->assignRole('lecturer');

        return $u;
    }

    public function test_setter_writes_every_type_and_html_is_sanitised(): void
    {
        $this->setUpPlatform();
        $setter = $this->setter();
        $base = ['mark' => 2, 'topic' => 'Stoichiometry', 'outcome' => 'LO1'];

        $this->actingAs($setter)->post('/question-bank/questions', $base + ['type' => 'single_choice', 'stem_html' => '<p onclick="x()">Pick \(x^2\)</p><script>alert(1)</script>',
            'options' => [['html' => 'One', 'correct' => false], ['html' => 'Two', 'correct' => true]]])->assertRedirect('/question-bank');
        $this->actingAs($setter)->post('/question-bank/questions', $base + ['type' => 'matching', 'stem_html' => 'Match',
            'pairs' => [['left' => 'Na', 'right' => 'Sodium'], ['left' => 'K', 'right' => 'Potassium']], 'distractors' => ['Krypton']])->assertRedirect();
        $this->actingAs($setter)->post('/question-bank/questions', $base + ['type' => 'short_answer', 'stem_html' => 'Symbol for gold?', 'accepted' => ['Au']])->assertRedirect();
        $this->actingAs($setter)->post('/question-bank/questions', $base + ['type' => 'numeric', 'stem_html' => 'g?', 'numeric' => ['value' => 9.8, 'tolerance' => 0.1]])->assertRedirect();

        $mcq = Question::where('type', 'single_choice')->first();
        $this->assertStringNotContainsString('script', $mcq->stem_html);
        $this->assertStringNotContainsString('onclick', $mcq->stem_html);
        $this->assertSame('draft', $mcq->status);
        $this->assertSame('LO1', $mcq->outcome);
        $m = Question::where('type', 'matching')->first()->matching;
        $this->assertCount(3, $m['right']);
        $this->assertSame(['1' => 'a', '2' => 'b'], $m['answer']);
        $this->assertSame(['answers' => ['Au'], 'case_sensitive' => false], Question::where('type', 'short_answer')->first()->accepted_answers);

        // a choice question without a correct option comes back as a form error, not an error page
        $this->actingAs($setter)->post('/question-bank/questions', ['type' => 'single_choice', 'stem_html' => 'x', 'mark' => 1,
            'options' => [['html' => 'a', 'correct' => false], ['html' => 'b', 'correct' => false]]])->assertSessionHasErrors('options');
    }

    public function test_editing_keeps_the_old_version_and_sends_approved_questions_back_for_review(): void
    {
        $this->setUpPlatform();
        $q = $this->questions()[0];
        $before = $q->stem_html;

        $this->actingAs($this->officer)->put("/question-bank/questions/{$q->id}", ['type' => 'single_choice', 'stem_html' => 'Reworded', 'mark' => 1,
            'options' => [['html' => 'x', 'correct' => true], ['html' => 'y', 'correct' => false]]])->assertRedirect();

        $q->refresh();
        $this->assertSame(2, $q->version);
        $this->assertSame('in_review', $q->status);
        $this->assertSame($before, $q->versions()->where('version', 1)->first()->snapshot['stem_html']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'question.edited']);
    }

    public function test_questions_being_answered_right_now_cannot_be_edited(): void
    {
        $this->setUpPlatform();
        $qs = $this->questions();
        $c = $this->candidate($this->sitting($this->cbtExam([], $qs)));
        $this->startAs($c)->assertOk();

        $this->actingAs($this->officer)->put("/question-bank/questions/{$qs[0]->id}", ['type' => 'single_choice', 'stem_html' => 'Changed', 'mark' => 1,
            'options' => [['html' => 'x', 'correct' => true], ['html' => 'y', 'correct' => false]]])->assertStatus(409);
    }

    public function test_setters_cannot_approve_their_own_questions(): void
    {
        $this->setUpPlatform();
        $moderator = User::factory()->create();
        $moderator->assignRole(['lecturer', 'moderator']);
        $own = Question::create(['type' => 'essay', 'stem_html' => 'Mine', 'mark' => 5, 'status' => 'draft', 'created_by' => $moderator->id]);
        $other = Question::create(['type' => 'essay', 'stem_html' => 'Theirs', 'mark' => 5, 'status' => 'draft', 'created_by' => $this->officer->id]);

        $this->actingAs($moderator)->post('/question-bank/moderate', ['ids' => [$own->id, $other->id], 'status' => 'approved'])->assertRedirect();

        $this->assertSame('draft', $own->fresh()->status);
        $this->assertSame('approved', $other->fresh()->status);
        // plain setters cannot moderate at all
        $this->actingAs($this->setter())->post('/question-bank/moderate', ['ids' => [$other->id], 'status' => 'retired'])->assertForbidden();
    }

    public function test_export_round_trips_through_the_importer(): void
    {
        $this->setUpPlatform();
        $this->questions();
        Question::create(['type' => 'matching', 'stem_html' => '<p>Match symbols</p>', 'mark' => 2, 'status' => 'approved', 'topic' => 'Elements',
            'matching' => Question::buildMatching([['left' => 'Na', 'right' => 'Sodium'], ['left' => 'Fe', 'right' => 'Iron']], fn ($h) => $h)]);
        Question::create(['type' => 'short_answer', 'stem_html' => '<p>Symbol of gold</p>', 'mark' => 1, 'status' => 'approved', 'accepted_answers' => ['answers' => ['Au'], 'case_sensitive' => false]]);

        $text = $this->actingAs($this->officer)->get('/question-bank/export')->assertOk()->getContent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'questions.exported']);

        $parsed = array_map(fn ($q) => $q->toArray(), (new QuestionParser)->parse((new DocumentConverter('none'))->textToHtml($text))['questions']);
        $this->assertSame(['single_choice', 'multiple_choice', 'numeric', 'essay', 'matching', 'short_answer'], array_column($parsed, 'type'));
        $this->assertSame([false, true, false], array_column($parsed[0]['options'], 'correct'));
        $this->assertSame(2.0, $parsed[1]['mark']);
        $this->assertSame('Elements', $parsed[4]['topic']);

        $this->actingAs($this->officer)->get('/question-bank/export?format=xlsx')->assertOk()->assertDownload();
    }

    public function test_matching_and_short_answers_are_marked_automatically(): void
    {
        $this->setUpPlatform();
        $match = Question::create(['type' => 'matching', 'stem_html' => 'Match', 'mark' => 4, 'status' => 'approved',
            'matching' => Question::buildMatching([['left' => 'Na', 'right' => 'Sodium'], ['left' => 'K', 'right' => 'Potassium'], '_distractors' => ['Krypton']], fn ($h) => $h)]);
        $short = Question::create(['type' => 'short_answer', 'stem_html' => 'Gold?', 'mark' => 1, 'status' => 'approved', 'accepted_answers' => ['answers' => ['Au', 'gold'], 'case_sensitive' => false]]);
        $exam = $this->cbtExam(['settings' => ['shuffle_questions' => false, 'shuffle_options' => false, 'show_score' => true, 'require_check_in' => false, 'partial_credit' => true]], [$match, $short]);
        $c = $this->candidate($this->sitting($exam));
        $token = $this->startAs($c)->json('token');
        $paper = collect($this->api($token, 'GET', 'paper')->assertOk()->json('paper.sections.0.questions'));
        $m = $paper->firstWhere('type', 'matching');
        $this->assertArrayNotHasKey('answer', $m['matching']); // the key never reaches the browser
        $key = fn ($html) => collect($m['matching']['right'])->firstWhere('html', $html)['key'];

        $this->api($token, 'POST', 'submit', ['answers' => [
            ['question_id' => $match->id, 'seq' => 1, 'response' => ['pairs' => ['1' => $key('Sodium'), '2' => $key('Krypton')]]],
            ['question_id' => $short->id, 'seq' => 2, 'response' => ['text' => '  AU ']],
        ]])->assertOk();

        $marks = \DB::table('attempt_answers')->where('attempt_id', $c->attempts()->first()->id)->pluck('score', 'question_id');
        $this->assertEquals(1, (float) $marks[$short->id]);
        $this->assertEquals(2, (float) $marks[$match->id]); // one of two pairs right, with partial credit on
    }
}
