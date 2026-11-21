<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\QuestionImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Each exam has its own question banks (one per topic if wanted); the old exam system's files
 * (plain Aiken text, its CSV sheets with pictures, Word, PDF text) upload straight into them; a
 * paper draws from one bank, several, or all with at least N from each; banks download and empty.
 */
class ExamQuestionBanksTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        Storage::fake('local');
        Storage::fake('public');
    }

    private const OLD_AIKEN = "What is the recommended angle of needle insertion for an intramuscular injection?\r\nA. 15 degrees\r\nB. 30 degrees\r\nC. 45 degrees\r\nD. 90 degrees \r\nANSWER: D\r\n"
        ."Which site is the safest for an intramuscular injection in an adult?\r\nA. Dorsogluteal\r\nB. Ventrogluteal\r\nC. Vastus lateralis\r\nD. Deltoid \r\nANSWER: B \r\n"
        ."When administering an oil-based medication, which technique prevents leakage?\r\nA. Z-track method\r\nB. Air-lock technique\r\nC. Massage vigorously\r\nD. Rapid injection \r\nANSWER: A The Z-track method seals the medication in the muscle.\r\n";

    private function draftExam(): Exam
    {
        return $this->cbtExam(['status' => 'draft', 'settings' => ['shuffle_questions' => true, 'shuffle_options' => true, 'require_check_in' => false]], []);
    }

    private function bank(Exam $exam, string $name): QuestionBank
    {
        $this->actingAs($this->officer)->post('/question-banks', ['name' => $name, 'exam_id' => $exam->id, 'scope' => 'exam'])->assertSessionHasNoErrors();

        return QuestionBank::where('name', $name)->sole();
    }

    private function upload(array $data): QuestionImport
    {
        $this->post('/question-bank/import', $data)->assertRedirect();

        return QuestionImport::latest('id')->firstOrFail();
    }

    public function test_the_old_systems_plain_text_file_uploads_into_an_exams_own_bank(): void
    {
        $exam = $this->draftExam();
        $bank = $this->bank($exam, 'Injections');
        // an exams officer who does not write questions can still fill the exam's own banks
        $officer = User::factory()->create(['user_type' => 'staff']);
        $officer->assignRole('exam_officer');

        $this->actingAs($officer);
        $import = $this->upload(['file' => UploadedFile::fake()->createWithContent('500L_QUESTION.txt', mb_convert_encoding(self::OLD_AIKEN, 'Windows-1252', 'UTF-8')), 'bank_id' => $bank->id]);
        $this->get("/question-bank/imports/{$import->id}")->assertOk()->assertInertia(fn ($p) => $p->has('questions', 3)
            ->where('questions.0.type', 'single_choice')->where('questions.0.line', 1)->where('questions.0.options.3.correct', true)
            ->where('questions.2.explanation_html', '<p>The Z-track method seals the medication in the muscle.</p>')
            ->where('destination.bank', 'Injections')->where('destination.exam.id', $exam->id));
        $this->post("/question-bank/imports/{$import->id}/commit")->assertRedirect("/exams/{$exam->id}?tab=banks");

        $this->assertSame(3, $bank->questions()->count());
        $this->assertSame(['approved'], $bank->questions()->distinct()->pluck('status')->all()); // checked with the paper
        // without a bank of an exam, an exams officer cannot add to the general question bank
        $this->post('/question-bank/import', ['file' => UploadedFile::fake()->createWithContent('a.txt', self::OLD_AIKEN)])->assertForbidden();
        $this->actingAs($this->officer)->get("/exams/{$exam->id}")->assertInertia(fn ($p) => $p->where('banks.0.name', 'Injections')->where('banks.0.questions', 3)->where('banks.0.types.single_choice', 3));
    }

    public function test_old_csv_sheets_with_pictures_and_every_type_in_one_text_file(): void
    {
        $exam = $this->draftExam();
        $bank = $this->bank($exam, 'Anatomy');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $zipPath = tempnam(sys_get_temp_dir(), 'z');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        foreach (['muscles.png', 'graph_a.png', 'graph_b.png'] as $n) {
            $zip->addFromString('pics/'.$n, $png);
        }
        $zip->close();
        $csv = "Question,Question_Image,Option A,Option_A_Image,Option B,Option_B_Image,Option C,Option_C_Image,Option D,Option_D_Image,Correct Option (A-D),Mark\n"
            ."Identify the muscle.,muscles.png,Deltoid,,Biceps,,Triceps,,Quadriceps,,A,2.5\n"
            ."Which graph?,,Graph A,graph_a.png,Graph B,graph_b.png,Graph C,missing.png,Graph D,,C,3\n";

        $import = $this->upload(['file' => UploadedFile::fake()->createWithContent('pictures.csv', $csv), 'bank_id' => $bank->id,
            'images' => [new UploadedFile($zipPath, 'pictures.zip', 'application/zip', null, true)]]);
        $this->get("/question-bank/imports/{$import->id}")->assertInertia(fn ($p) => $p->has('questions', 2)
            ->where('questions.0.mark', 2.5)->where('questions.0.stem_html', fn ($h) => str_contains($h, '<img src="/storage/question-media/'))
            ->where('questions.1.options.0.html', fn ($h) => str_contains($h, '<img'))
            ->where('questions.1.warnings', fn ($w) => str_contains(collect($w)->implode(' '), 'missing.png'))
            ->where('destination.pictures', 3));

        // the old "CSV saved as .txt" sample is read as a sheet
        $this->upload(['file' => UploadedFile::fake()->createWithContent('sample.txt', "question_text,option_a,option_b,option_c,option_d,correct_option\nCapital of France?,Paris,London,Berlin,Madrid,A\n"), 'bank_id' => $bank->id]);
        $this->assertSame('csv', QuestionImport::latest('id')->first()->format);

        // the "every type" template gives one question of each type
        $all = $this->upload(['file' => UploadedFile::fake()->createWithContent('t.txt', \App\Support\Templates::content('questions_all_txt')), 'bank_id' => $bank->id]);
        $this->post("/question-bank/imports/{$all->id}/commit");
        $this->assertEqualsCanonicalizing(Question::TYPES, Question::where('question_import_id', $all->id)->distinct()->pluck('type')->all());
        $this->assertSame('partial', Question::where('question_import_id', $all->id)->where('type', 'multiple_choice')->value('scoring'));
    }

    public function test_a_paper_draws_from_several_banks_with_at_least_n_from_each(): void
    {
        $exam = $this->draftExam();
        $banks = collect(['Topic 1', 'Topic 2', 'Topic 3'])->map(fn ($n) => $this->bank($exam, $n));
        foreach ($banks as $b) {
            for ($i = 1; $i <= 4; $i++) {
                $q = Question::create(['type' => 'single_choice', 'stem_html' => "<p>{$b->name} question $i</p>", 'mark' => 1, 'status' => 'draft', 'question_bank_id' => $b->id, 'created_by' => $this->officer->id]);
                $q->options()->create(['label' => 'A', 'body_html' => 'yes', 'is_correct' => true, 'position' => 0]);
                $q->options()->create(['label' => 'B', 'body_html' => 'no', 'is_correct' => false, 'position' => 1]);
            }
        }
        $section = $exam->sections()->first();
        $ids = $banks->pluck('id')->all();

        // asking for more than a bank holds is caught before the paper is submitted
        $this->put("/exams/{$exam->id}/sections/{$section->id}", ['blueprint' => [['count' => 6, 'bank_ids' => $ids, 'min_each' => 5]]])->assertSessionHasNoErrors();
        $this->get("/exams/{$exam->id}")->assertInertia(fn ($p) => $p->where('problems', fn ($pr) => str_contains(collect($pr)->implode(' '), '"Topic 1" has 4 questions; the rule needs at least 5')));
        $this->post("/exams/{$exam->id}/transition", ['action' => 'submit'])->assertStatus(422);

        // a bank from another exam cannot be used
        $other = $this->bank($this->draftExam(), 'Elsewhere');
        $this->put("/exams/{$exam->id}/sections/{$section->id}", ['blueprint' => [['count' => 2, 'bank_ids' => [$other->id]]]])->assertStatus(422);

        $this->put("/exams/{$exam->id}/sections/{$section->id}", ['blueprint' => [['count' => 7, 'bank_ids' => $ids, 'min_each' => 2]]])->assertSessionHasNoErrors();
        $this->get("/exams/{$exam->id}")->assertInertia(fn ($p) => $p->where('problems', []));
        $exam->update(['status' => 'scheduled']);
        $session = $this->sitting($exam);

        $orders = [];
        for ($n = 0; $n < 5; $n++) {
            $res = $this->startAs($this->candidate($session));
            $this->assertNotNull($res->json("token"), $res->getContent());
            $token = $res->json("token");
            $qs = $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions');
            $this->assertCount(7, $qs);
            $perBank = collect($qs)->countBy(fn ($q) => Question::find($q['id'])->question_bank_id);
            foreach ($ids as $id) {
                $this->assertGreaterThanOrEqual(2, $perBank[$id] ?? 0, 'every topic is covered');
            }
            $orders[] = implode(',', array_column($qs, 'id'));
        }
        $this->assertGreaterThan(1, count(array_unique($orders)), 'each student gets their own draw and order');
    }

    public function test_uploading_straight_onto_a_section_and_putting_whole_banks_on_the_paper(): void
    {
        $exam = $this->draftExam();
        $section = $exam->sections()->first();
        $import = $this->actingAs($this->officer)->upload(['file' => UploadedFile::fake()->createWithContent('paper.txt', self::OLD_AIKEN), 'section_id' => $section->id]);
        $this->post("/question-bank/imports/{$import->id}/commit")->assertRedirect("/exams/{$exam->id}?tab=banks");
        $this->assertSame(3, $section->questions()->count());
        $this->assertSame('Uploaded questions', QuestionBank::where('exam_id', $exam->id)->value('name'));

        $bank = $this->bank($exam, 'Extra');
        $more = $this->upload(['file' => UploadedFile::fake()->createWithContent('b.txt', self::OLD_AIKEN), 'bank_id' => $bank->id]);
        $this->post("/question-bank/imports/{$more->id}/commit");
        $this->post("/exams/{$exam->id}/sections/{$section->id}/banks", ['bank_ids' => [$bank->id]])->assertSessionHasNoErrors();
        $this->assertSame(6, $section->questions()->count());

        // clear the paper: the banks keep their questions
        $this->post("/exams/{$exam->id}/clear-paper");
        $this->assertSame(0, $section->questions()->count());
        $this->assertSame(3, $bank->questions()->count());
    }

    public function test_banks_download_in_the_upload_layout_and_can_be_emptied_and_deleted(): void
    {
        $exam = $this->draftExam();
        $bank = $this->bank($exam, 'Liver');
        $import = $this->actingAs($this->officer)->upload(['file' => UploadedFile::fake()->createWithContent('t.txt', \App\Support\Templates::content('questions_all_txt')), 'bank_id' => $bank->id]);
        $this->post("/question-bank/imports/{$import->id}/commit");

        $text = $this->get("/question-banks/{$bank->id}/export.txt")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="liver.txt"')->getContent();
        $this->assertStringContainsString("TYPE: MTF\r\nMARK: 4\r\nConcerning the liver:\r\na) It is the largest gland in the body. [T]", $text);
        // uploading the download again gives the same questions
        $again = $this->upload(['file' => UploadedFile::fake()->createWithContent('liver.txt', $text), 'bank_id' => $bank->id]);
        $this->get("/question-bank/imports/{$again->id}")->assertInertia(fn ($p) => $p->has('questions', 12)->where('documentWarnings', []));
        $this->get("/question-banks/{$bank->id}/export.csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->get("/exams/{$exam->id}/questions.txt")->assertOk();

        $this->delete("/question-banks/{$bank->id}")->assertSessionHas('error');
        $this->post("/question-banks/{$bank->id}/clear")->assertSessionHas('success', '12 questions removed from "Liver".');
        $this->assertSame(0, $bank->questions()->count());
        $this->delete("/question-banks/{$bank->id}")->assertSessionHas('success');
        $this->assertNull($bank->fresh());
    }

    public function test_a_questions_own_scoring_rule_wins_over_the_exams(): void
    {
        $exam = $this->draftExam();
        $bank = $this->bank($exam, 'MTF');
        $file = "TYPE: MTF\nMARK: 4\nSCORING: ALL\nRegarding malaria:\na) Protozoan [T]\nb) Male mosquito [F]\nc) Falciparum severe [T]\nd) Waterborne [F]\n";
        $import = $this->actingAs($this->officer)->upload(['file' => UploadedFile::fake()->createWithContent('m.txt', $file), 'section_id' => $exam->sections()->first()->id]);
        $this->post("/question-bank/imports/{$import->id}/commit");
        $exam->update(['status' => 'scheduled', 'settings' => ['shuffle_questions' => false, 'shuffle_options' => false, 'require_check_in' => false]]);
        $q = Question::where('question_import_id', $import->id)->sole();
        $this->assertSame('all', $q->scoring);

        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        [$a, $b, $c, $d] = array_column($this->api($token, 'GET', 'paper')->json('paper.sections.0.questions.0.options'), 'id');
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q->id, 'seq' => 1, 'response' => ['tf' => [$a => true, $b => false, $c => true, $d => true]]]]]);
        $this->api($token, 'POST', 'submit')->assertOk();
        $this->assertSame(0.0, (float) Attempt::first()->score_raw); // 3 of 4 right is nothing when all must be right
    }

    public function test_without_paper_approval_the_setter_publishes_in_one_step(): void
    {
        $exam = $this->draftExam();
        $import = $this->actingAs($this->officer)->upload(['file' => UploadedFile::fake()->createWithContent('p.txt', self::OLD_AIKEN), 'section_id' => $exam->sections()->first()->id]);
        $this->post("/question-bank/imports/{$import->id}/commit");
        $this->get("/exams/{$exam->id}")->assertInertia(fn ($p) => $p->where('exam.needs_approval', false));
        $this->post("/exams/{$exam->id}/transition", ['action' => 'publish'])->assertSessionHas('success');
        $this->assertSame('scheduled', $exam->fresh()->status);
        $this->assertSame(1, \App\Models\ExamVersion::where('exam_id', $exam->id)->count()); // the published paper is frozen as before

        // with approval switched on, publishing alone is refused
        $other = $this->draftExam();
        $this->tenant->update(['settings' => array_replace_recursive((array) $this->tenant->settings, ['policy' => ['exam_paper_approval' => true]])]);
        $this->post("/exams/{$other->id}/transition", ['action' => 'publish'])->assertStatus(422);
    }

    /** T2: uploaded questions reach students shuffled, show every type, and mark as the old system did. */
    public function test_uploaded_questions_are_shuffled_for_students_and_marked_right(): void
    {
        $exam = $this->draftExam();
        $import = $this->actingAs($this->officer)->upload(['file' => UploadedFile::fake()->createWithContent('500L.txt', self::OLD_AIKEN.\App\Support\Templates::content('questions_mtf_txt')), 'section_id' => $exam->sections()->first()->id]);
        $this->post("/question-bank/imports/{$import->id}/commit");
        $this->post("/exams/{$exam->id}/transition", ['action' => 'publish'])->assertSessionHas('success');
        $session = $this->sitting($exam);

        $orders = [];
        $optionOrders = [];
        for ($i = 0; $i < 6; $i++) {
            $token = $this->startAs($this->candidate($session))->json('token');
            $qs = $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions');
            $this->assertCount(5, $qs);
            $this->assertStringNotContainsString('correct', json_encode($qs)); // no answers in the student's copy
            $orders[] = implode(',', array_column($qs, 'id'));
            $first = collect($qs)->firstWhere('type', 'single_choice');
            $optionOrders[] = implode(',', array_column($first['options'], 'id'));
            $this->assertSame(['A', 'B', 'C', 'D'], array_column($first['options'], 'label')); // letters follow the shown order
            if ($i === 0) {
                // answer everything right, as the old system's key says
                $answers = [];
                foreach ($qs as $n => $q) {
                    $model = Question::with('options')->find($q['id']);
                    $answers[] = ['question_id' => $q['id'], 'seq' => $n + 1, 'response' => $q['type'] === 'multi_true_false'
                        ? ['tf' => $model->options->mapWithKeys(fn ($o) => [$o->id => (bool) $o->is_correct])->all()]
                        : ['options' => $model->options->where('is_correct', true)->pluck('id')->all()]];
                }
                $this->api($token, 'POST', 'answers', ['answers' => $answers])->assertJsonPath('rejected', []);
                $this->api($token, 'POST', 'submit')->assertOk();
                $this->assertSame(12.0, (float) \App\Models\Attempt::latest('id')->first()->score_raw); // 3 x 1 + MTF 4 + MTF 5
            }
        }
        $this->assertGreaterThan(1, count(array_unique($orders)), 'question order is shuffled per student');
        $this->assertGreaterThan(1, count(array_unique($optionOrders)), 'option order is shuffled per student');
    }

    public function test_every_question_template_downloads(): void
    {
        foreach (['questions_all_txt', 'questions_all_docx', 'questions_all_pdf', 'questions_simple_txt', 'questions_mtf_txt', 'questions_csv', 'questions_csv_simple', 'questions_csv_pictures', 'questions_pictures_zip', 'practical_checklist'] as $k) {
            $this->actingAs($this->officer)->get("/templates/$k")->assertOk();
        }
        $this->assertStringStartsWith('%PDF', $this->get('/templates/questions_all_pdf')->getContent());
        $this->assertTrue(DB::table('question_banks')->doesntExist());
    }
}
