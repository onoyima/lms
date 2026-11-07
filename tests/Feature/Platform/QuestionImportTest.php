<?php

namespace Tests\Feature\Platform;

use App\Models\Plan;
use App\Models\Question;
use App\Models\User;
use App\Services\Questions\Import\DocumentConverter;
use App\Services\Questions\RichTextSanitizer;
use App\Services\Tenancy\TenantProvisioner;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuestionImportTest extends TestCase
{
    use RefreshDatabase;

    private User $setter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(PlanSeeder::class);
        $this->tenant->update(['plan_id' => Plan::where('key', 'exams_only')->value('id')]);
        app(TenantProvisioner::class)->provision(['slug' => $this->tenant->slug, 'name' => $this->tenant->name], 'exams_only');
        $this->setter = User::factory()->create();
        $this->setter->assignRole('question_setter');
    }

    public function test_a_word_document_with_equations_imports_with_its_maths(): void
    {
        if (! (new DocumentConverter)->pandocAvailable()) {
            $this->markTestSkipped('pandoc not installed');
        }

        $file = new UploadedFile(base_path('tests/Fixtures/chemistry_physics_maths.docx'), 'paper.docx', null, null, true);

        $res = $this->actingAs($this->setter)->post('/question-bank/import', ['file' => $file]);
        $import = \App\Models\QuestionImport::first();
        $res->assertRedirect("/question-bank/imports/{$import->id}");
        $this->assertSame('parsed', $import->status);
        $this->assertSame(7, $import->question_count);

        $this->actingAs($this->setter)->get("/question-bank/imports/{$import->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('question-bank/import-preview')
                ->has('questions', 7)
                ->where('questions.0.options.1.correct', true)
                ->where('questions.3.type', 'multiple_choice')
                ->where('questions.4.type', 'numeric'));

        // Word's own equation (kinetic energy) came through as LaTeX
        $preview = app(\App\Services\Questions\Import\QuestionImportService::class)->parse($import);
        $text = fn (string $html) => html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('\frac{1}{2}mv^{2}', $text($preview['questions'][1]['stem_html']));
        $this->assertStringContainsString('\(\ce{2H2 + O2 -> 2H2O}\)', $text($preview['questions'][0]['options'][1]['html']));

        $this->actingAs($this->setter)->post("/question-bank/imports/{$import->id}/commit")->assertRedirect('/question-bank');

        $this->assertSame(7, Question::count());
        $this->assertSame(4, Question::first()->options()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'question_import.committed']);

        // cannot be saved twice
        $this->actingAs($this->setter)->post("/question-bank/imports/{$import->id}/commit")->assertStatus(422);
    }

    public function test_a_text_file_imports_without_pandoc(): void
    {
        $file = UploadedFile::fake()->createWithContent('q.txt', "1. Speed of light?\nA. \$3\\times10^8\$ m/s *\nB. \$3\\times10^6\$ m/s");

        $this->actingAs($this->setter)->post('/question-bank/import', ['file' => $file])->assertRedirect();

        $this->assertSame(1, \App\Models\QuestionImport::first()->question_count);
    }

    public function test_scripts_in_a_document_are_removed(): void
    {
        $clean = app(RichTextSanitizer::class)->clean('<p onclick="x()">Hi <script>alert(1)</script><img src="x" onerror="alert(1)"><span class="math inline evil">\(x\)</span></p>');

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringContainsString('<span class="math inline">\(x\)</span>', $clean);
    }

    public function test_other_file_types_are_refused(): void
    {
        $file = UploadedFile::fake()->create('shell.php', 1);

        $this->actingAs($this->setter)->post('/question-bank/import', ['file' => $file])->assertSessionHasErrors('file');
    }

    public function test_students_cannot_import(): void
    {
        $student = User::factory()->create(['user_type' => 'student']);
        $student->assignRole('student');

        $this->actingAs($student)->get('/question-bank/import')->assertForbidden();
    }
}
