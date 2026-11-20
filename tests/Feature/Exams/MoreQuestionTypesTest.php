<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\Question;
use App\Models\QuestionPassage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Put in order, fill in the blanks, file upload, shared passages, and pictures / sound / video in questions. */
class MoreQuestionTypesTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function save(array $q): Question
    {
        $this->actingAs($this->officer)->post('/question-bank/questions', $q + ['mark' => 3])->assertSessionHasNoErrors();
        $question = Question::latest('id')->first();
        $question->update(['status' => 'approved']);

        return $question;
    }

    public function test_ordering_and_fill_in_the_blanks_are_set_answered_and_marked(): void
    {
        $order = $this->save(['type' => 'ordering', 'stem_html' => '<p>Order the stages of mitosis.</p>', 'options' => [['html' => 'Prophase'], ['html' => 'Metaphase'], ['html' => 'Anaphase'], ['html' => 'Telophase']]]);
        $this->actingAs($this->officer)->post('/question-bank/questions', ['type' => 'fill_blank', 'mark' => 2, 'stem_html' => '<p>The [[1]] is the powerhouse; \\(g = [[2]]\\,\\mathrm{m\\,s^{-2}}\\).</p>', 'blanks' => [['answers' => ['mitochondria']]]])
            ->assertSessionHasErrors('blanks'); // two blanks in the text, one answer given
        $blank = $this->save(['type' => 'fill_blank', 'stem_html' => '<p>The [[1]] is the powerhouse; g is about [[2]] m/s².</p>',
            'blanks' => [['answers' => ['mitochondria', 'mitochondrion']], ['value' => 9.8, 'tolerance' => 0.1, 'answers' => []]]]);

        $exam = $this->cbtExam(['total_mark' => 6, 'settings' => ['shuffle_questions' => false, 'shuffle_options' => false, 'require_check_in' => false, 'partial_credit' => true]], [$order, $blank]);
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        [$po, $pb] = $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions');
        $this->assertSame([1, 2], $pb['blanks']);
        $right = $order->options()->orderBy('position')->pluck('id')->all();
        $this->assertNotSame($right, array_column($po['options'], 'id')); // never shown in the right order
        $this->assertStringNotContainsString('mitochondria', json_encode($pb));

        // first two in place, last two swapped: 2 of 4 positions with partial credit = 1.5 of 3
        $this->api($token, 'POST', 'answers', ['answers' => [
            ['question_id' => $order->id, 'seq' => 1, 'response' => ['order' => [$right[0], $right[1], $right[3], $right[2]]]],
            ['question_id' => $blank->id, 'seq' => 2, 'response' => ['blanks' => ['1' => ' Mitochondria. ', '2' => '9,81']]],
        ]])->assertJsonPath('rejected', []);
        // an order that leaves an item out is refused
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $order->id, 'seq' => 3, 'response' => ['order' => [$right[0]]]]]])->assertJsonPath('rejected', [$order->id]);
        $this->api($token, 'POST', 'submit')->assertOk();
        $this->assertSame(1.5 + 3.0, (float) Attempt::first()->score_raw);
    }

    public function test_a_file_answer_is_handed_in_and_opened_by_the_examiner(): void
    {
        $q = $this->save(['type' => 'file_upload', 'stem_html' => '<p>Draw and photograph the circuit.</p>', 'file' => ['kinds' => ['image', 'pdf'], 'max_mb' => 2]]);
        $exam = $this->cbtExam([], [$q]);
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        $this->assertSame(['kinds' => ['image', 'pdf'], 'max_mb' => 2], $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions.0.file'));

        $send = fn ($file) => $this->withHeaders(['Authorization' => "Bearer $token"])->post('/api/exam/attempt/file', ['question_id' => $q->id, 'file' => $file]);
        $send(UploadedFile::fake()->create('report.docx', 10))->assertStatus(422);
        $send(UploadedFile::fake()->create('big.pdf', 3000))->assertStatus(422);
        $file = $send(UploadedFile::fake()->image('circuit.jpg', 800, 600))->assertOk()->json();

        // the answer must point at a file this candidate sent for this question
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q->id, 'seq' => 1, 'response' => ['file' => ['id' => 999999]]]]])->assertJsonPath('rejected', [$q->id]);
        $this->api($token, 'POST', 'submit', ['answers' => [['question_id' => $q->id, 'seq' => 2, 'response' => ['file' => $file]]]])->assertOk();
        $this->assertTrue(Attempt::first()->needs_marking);

        $answers = $this->actingAs($this->officer)->get("/marking/{$exam->id}/essays")->viewData('page')['props']['answers'];
        $this->assertSame('circuit.jpg', $answers[0]['file']['name']);
        $this->get($answers[0]['file']['url'])->assertOk()->assertDownload('circuit.jpg');
    }

    public function test_questions_on_one_passage_stay_together_and_carry_it(): void
    {
        $passage = QuestionPassage::create(['title' => 'Case: Mr B, 58', 'body_html' => '<p>Chest pain for two hours…</p>']);
        $qs = collect(range(1, 6))->map(fn ($i) => tap(Question::create(['type' => 'essay', 'stem_html' => "<p>Q$i</p>", 'mark' => 1, 'status' => 'approved', 'passage_id' => $i <= 3 ? $passage->id : null]))->refresh());
        $exam = $this->cbtExam(['settings' => ['shuffle_questions' => true, 'require_check_in' => false]], $qs->all());
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        $paper = $this->api($token, 'GET', 'paper')->json('paper');
        $this->assertSame('Case: Mr B, 58', $paper['passages'][$passage->id]['title']);
        $withPassage = array_keys(array_filter(array_column($paper['sections'][0]['questions'], 'passage_id')));
        $this->assertCount(3, $withPassage);
        $this->assertSame(range($withPassage[0], $withPassage[0] + 2), $withPassage); // next to each other
    }

    public function test_pictures_sound_and_video_can_be_put_in_a_question(): void
    {
        $this->actingAs($this->officer)->post('/question-bank/media', ['file' => UploadedFile::fake()->create('listening.mp3', 200, 'audio/mpeg')])
            ->assertOk()->assertJsonPath('kind', 'audio')->assertJson(fn ($j) => $j->where('html', fn ($h) => str_starts_with($h, '<audio controls src="/storage/question-media/'))->etc());
        $this->post('/question-bank/media', ['file' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('file');
        $res = $this->postJson('/question-bank/passages', ['title' => 'Comprehension', 'body_html' => '<p>Read this.</p><script>x</script>'])->assertOk();
        $this->assertStringNotContainsString('script', QuestionPassage::find($res->json('id'))->body_html);
        $q = $this->save(['type' => 'single_choice', 'stem_html' => '<p>Listen.</p><audio controls src="/storage/a.mp3"></audio>', 'passage_id' => $res->json('id'),
            'options' => [['html' => 'Yes', 'correct' => true], ['html' => 'No', 'correct' => false]]]);
        $this->assertStringContainsString('<audio controls', $q->stem_html);
        $this->assertSame($res->json('id'), $q->passage_id);
    }

    public function test_word_template_for_blanks_and_ordering(): void
    {
        $html = (new \App\Services\Questions\Import\DocumentConverter('pandoc-not-needed'))->textToHtml(
            "1. The ___ carries oxygen and ___ fights infection.\nType: fill in the blanks\nAnswer: haemoglobin|hemoglobin; white cells\n\n2. Arrange from smallest:\nA. atom\nB. cell\nC. organ\nType: order"
        );
        [$a, $b] = array_map(fn ($q) => $q->toArray(), (new \App\Services\Questions\Import\QuestionParser)->parse($html)['questions']);
        $this->assertSame('fill_blank', $a['type']);
        $this->assertStringContainsString('[[1]]', $a['stem_html']);
        $this->assertStringContainsString('[[2]]', $a['stem_html']);
        $this->assertSame([['answers' => ['haemoglobin', 'hemoglobin']], ['answers' => ['white cells']]], $a['blanks']);
        $this->assertSame('ordering', $b['type']);
        $this->assertSame(['A', 'B', 'C'], array_column($b['options'], 'label'));
    }
}
