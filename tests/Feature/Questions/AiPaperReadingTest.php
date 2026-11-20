<?php

namespace Tests\Feature\Questions;

use App\Models\Question;
use App\Models\QuestionImport;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\PaperReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/**
 * Reading a question paper with AI (the AI service is faked here): photos of the pages go in; questions
 * with maths, chemistry, the answer key and a cut-out diagram come out for the setter to check; nothing
 * is saved until the setter confirms. AI stays off until a key is set.
 */
class AiPaperReadingTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function page(): UploadedFile
    {
        $img = imagecreatetruecolor(800, 1000);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 100, 400, 500, 700, imagecolorallocate($img, 0, 0, 0)); // "the diagram"
        ob_start();
        imagepng($img);

        return UploadedFile::fake()->createWithContent('page-001.png', (string) ob_get_clean());
    }

    private function reply(): array
    {
        return ['content' => [['type' => 'text', 'text' => "```json\n".json_encode(['questions' => [
            ['number' => 1, 'type' => 'single_choice', 'stem' => '<p>Balance \\(\\ce{H2 + O2 -> H2O}\\). What is the coefficient of water?</p>',
                'options' => [['label' => 'A', 'html' => '1', 'correct' => false], ['label' => 'B', 'html' => '2', 'correct' => true]], 'mark' => 2, 'confidence' => 'high'],
            ['number' => 2, 'type' => 'essay', 'stem' => '<p>Find \\(\\int_0^1 x^2\\,dx\\) and explain the circuit shown.</p>',
                'figures' => [['page' => 1, 'box' => [0.1, 0.38, 0.65, 0.72], 'for' => 'stem']], 'mark' => 10, 'confidence' => 'low', 'notes' => 'The resistor value is smudged.'],
            ['number' => 3, 'type' => 'single_choice', 'stem' => '<p>Unit of force?</p>', 'options' => [['label' => 'A', 'html' => 'N'], ['label' => 'B', 'html' => 'J']]],
        ]])."\n```"]], 'usage' => ['input_tokens' => 1500, 'output_tokens' => 600]];
    }

    public function test_ai_is_off_until_a_key_is_set_then_reads_a_paper_for_review(): void
    {
        $this->actingAs($this->officer)->get('/question-bank/import')->assertInertia(fn ($p) => $p->where('ai', false));
        $this->post('/question-bank/import', ['reader' => 'ai', 'pages' => [$this->page()]])->assertStatus(422);

        // the institution adds its own Claude key (kept encrypted, never sent back)
        $this->put('/admin/ai', ['provider' => 'anthropic', 'key' => 'sk-ant-test-123', 'features' => ['read_papers' => true]])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('sk-ant-test-123', json_encode($this->tenant->fresh()->settings));
        $this->get('/admin/ai')->assertInertia(fn ($p) => $p->where('current.has_key', true)->missing('current.key')->where('active.source', 'institution'));

        Http::fake(['api.anthropic.com/*' => Http::response($this->reply())]);
        $res = $this->post('/question-bank/import', ['reader' => 'ai', 'name' => 'chemistry.pdf', 'pages' => [$this->page()]]);
        $import = QuestionImport::sole();
        $res->assertRedirect(route('question-bank.imports.show', $import));
        $this->assertSame('parsed', $import->fresh()->status); // the queue runs at once in tests

        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'sk-ant-test-123') && $r['messages'][0]['content'][2]['type'] === 'image'
            && str_contains($r['system'], 'mhchem') && $r['model'] === AiSettings::DEFAULT_MODEL['anthropic']);

        $page = $this->get("/question-bank/imports/{$import->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(1, $page['pages']);
        [$q1, $q2, $q3] = $page['questions'];
        $this->assertSame([false, true], array_column($q1['options'], 'correct'));
        $this->assertStringContainsString('\ce{H2 + O2 -> H2O}', html_entity_decode($q1['stem_html']));
        $this->assertSame(2.0, (float) $q1['mark']);
        $this->assertSame('essay', $q2['type']);
        $this->assertMatchesRegularExpression('#<img src="/storage/question-media/\d+/fig-\w+\.png"#', $q2['stem_html']);
        $this->assertStringContainsString('smudged', implode(' ', $q2['warnings']));
        $this->assertStringContainsString('No answer shown', implode(' ', $q3['warnings']));
        $fig = substr($q2['stem_html'], strpos($q2['stem_html'], '/storage/') + 9);
        $fig = substr($fig, 0, strpos($fig, '"'));
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($fig));
        $this->assertSame([440, 340], [$w, $h]); // the box, cut out of the 800 × 1000 page

        $this->get("/question-bank/imports/{$import->id}/pages/1")->assertOk();
        $this->assertSame(0, Question::count()); // nothing saved before the setter confirms
        $this->post("/question-bank/imports/{$import->id}/commit")->assertRedirect();
        $this->assertSame(3, Question::where('status', 'draft')->count());
    }

    public function test_the_platform_ai_is_used_when_shared_and_failures_can_be_retried(): void
    {
        $platform = User::factory()->create(['tenant_id' => null, 'is_platform_admin' => true]);
        $this->actingAs($platform)->put('/platform/ai', ['provider' => 'openai', 'base_url' => 'http://10.0.0.5:11434/v1', 'model' => 'llava', 'key' => 'local-key', 'shared' => true])->assertSessionHasNoErrors();
        $this->assertNotContains('local-key', \App\Support\Platform\PlatformSettings::all());

        Http::fake(['10.0.0.5:11434/*' => Http::sequence()->push(['error' => ['message' => 'model not loaded']], 400)->push(['choices' => [['message' => ['content' => json_encode(['questions' => [['number' => 1, 'type' => 'true_false', 'stem' => '<p>Water boils at 100 °C at sea level.</p>', 'options' => [['label' => 'A', 'html' => 'True', 'correct' => true], ['label' => 'B', 'html' => 'False', 'correct' => false]]]]])]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]])]);
        $this->actingAs($this->officer)->get('/admin/ai')->assertInertia(fn ($p) => $p->where('active.source', 'platform'));
        $this->post('/question-bank/import', ['reader' => 'ai', 'pages' => [$this->page()]]);
        $import = QuestionImport::sole();
        $this->assertSame('failed', $import->fresh()->status);
        $this->assertStringContainsString('model not loaded', $import->fresh()->warnings[0]);

        $this->post("/question-bank/imports/{$import->id}/retry")->assertRedirect();
        $this->assertSame('parsed', $import->fresh()->status);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://10.0.0.5:11434/v1/chat/completions') && $r['messages'][1]['content'][2]['type'] === 'image_url');

        // the institution can switch the platform's AI off for itself
        $this->put('/admin/ai', ['provider' => 'anthropic', 'use_platform' => false])->assertSessionHasNoErrors();
        $this->assertNull(AiSettings::resolve($this->tenant->fresh()));
    }

    public function test_long_papers_are_read_in_overlapping_batches_without_repeats(): void
    {
        $this->actingAs($this->officer)->put('/admin/ai', ['provider' => 'anthropic', 'key' => 'k']);
        $batch = fn (array $numbers) => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['questions' => array_map(fn ($n) => ['number' => $n, 'type' => 'essay', 'stem' => "<p>Q$n</p>"], $numbers)])]]]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()->pushResponse($batch([1, 2, 3, 4]))->pushResponse($batch([3, 4, 5, 6]))]);
        $this->post('/question-bank/import', ['reader' => 'ai', 'pages' => array_map(fn () => $this->page(), range(1, PaperReader::PAGES_PER_REQUEST + 2))]);
        $numbers = array_column($this->get('/question-bank/imports/'.QuestionImport::sole()->id)->viewData('page')['props']['questions'], 'number');
        $this->assertSame([1, 2, 3, 4, 5, 6], $numbers);
        Http::assertSentCount(2);
    }

    public function test_ai_writes_draft_questions_for_review(): void
    {
        $this->actingAs($this->officer)->put('/admin/ai', ['provider' => 'anthropic', 'key' => 'k']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['questions' => [
            ['type' => 'multi_true_false', 'stem' => '<p>Buffers:</p>', 'options' => [['label' => 'A', 'html' => 'resist pH change', 'correct' => true], ['label' => 'B', 'html' => 'are strong acids', 'correct' => false]], 'explanation' => '<p>A buffer…</p>', 'mark' => 2],
            ['type' => 'essay', 'stem' => '<p>Derive the Henderson–Hasselbalch equation.</p>', 'explanation' => '<p>3 marks: …</p>', 'mark' => 6],
        ]])]]])]);
        $this->post('/question-bank/write', ['topic' => 'Buffers', 'types' => ['multi_true_false', 'essay'], 'count' => 2, 'notes' => 'Buffers resist changes in pH.'])->assertRedirect();
        $import = QuestionImport::sole();
        $this->assertSame(['ai-gen', 'parsed'], [$import->format, $import->status]);
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'][0]['text'], 'Buffers resist changes in pH.'));
        $qs = $this->get("/question-bank/imports/{$import->id}")->viewData('page')['props']['questions'];
        $this->assertSame(['multi_true_false', 'essay'], array_column($qs, 'type'));
        $this->assertSame([1, 2], array_column($qs, 'number'));
        $this->post("/question-bank/imports/{$import->id}/commit");
        $this->assertSame(2, Question::where('status', 'draft')->count());
    }

    public function test_ai_suggests_a_mark_that_never_becomes_the_mark_by_itself(): void
    {
        $this->actingAs($this->officer)->put('/admin/ai', ['provider' => 'anthropic', 'key' => 'k']);
        $essay = Question::create(['type' => 'essay', 'stem_html' => '<p>Explain osmosis.</p>', 'explanation_html' => '<p>Water, membrane, concentration gradient.</p>', 'mark' => 5, 'status' => 'approved']);
        $exam = $this->cbtExam(['total_mark' => 5], [$essay]);
        $c = $this->candidate($this->sitting($exam));
        $token = $this->startAs($c)->json('token');
        $this->api($token, 'POST', 'submit', ['answers' => [['question_id' => $essay->id, 'seq' => 1, 'response' => ['text' => 'Water moves through a membrane.']]]]);
        $attempt = \App\Models\Attempt::sole();

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"score": 3.3, "points": [{"point": "membrane", "credit": 2}], "reasons": "Gradient not mentioned."}']]])]);
        $this->actingAs($this->officer)->postJson("/marking/{$exam->id}/essays/suggest", ['attempt_id' => $attempt->id, 'question_id' => $essay->id])
            ->assertOk()->assertJsonPath('score', 3.5);
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'][0]['text'], 'Water moves through a membrane.') && ! str_contains($r['messages'][0]['content'][0]['text'], $c->user->name));
        $this->assertNull(\Illuminate\Support\Facades\DB::table('attempt_answers')->where('attempt_id', $attempt->id)->value('score'));
        $this->assertTrue($attempt->fresh()->needs_marking);
        $this->get("/marking/{$exam->id}/essays")->assertInertia(fn ($p) => $p->where('ai', true)->where('answers.0.suggestion.score', 3.5));
    }
}
