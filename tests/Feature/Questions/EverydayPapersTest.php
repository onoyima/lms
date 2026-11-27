<?php

namespace Tests\Feature\Questions;

use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/** Question papers as lecturers actually keep them upload straight onto an exam. */
class EverydayPapersTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function upload(string $path, string $name): \App\Models\QuestionImport
    {
        $exam = $this->cbtExam(['status' => 'draft'], []);
        $section = $exam->sections()->first() ?? $exam->sections()->create(['title' => 'Section A', 'position' => 0]);
        $this->actingAs($this->officer)->post('/question-bank/import', ['file' => new UploadedFile($path, $name, null, null, true), 'section_id' => $section->id, 'reader' => 'science'])
            ->assertRedirect();

        return \App\Models\QuestionImport::latest('id')->first();
    }

    public function test_an_old_word_file_with_an_answer_key_at_the_end(): void
    {
        $import = $this->upload(base_path('tests/Fixtures/questions-with-answer-key.doc'), 'paper.doc');
        $this->assertSame('parsed', $import->status);
        $this->assertSame(3, $import->question_count);
        $this->actingAs($this->officer)->post("/question-bank/imports/{$import->id}/commit")->assertRedirect();
        $q = Question::orderBy('id')->first();
        $this->assertSame(['C'], $q->options()->where('is_correct', true)->pluck('label')->all()); // from the key at the end
    }

    public function test_a_word_file_renamed_doc_and_an_excel_sheet(): void
    {
        $import = $this->upload(database_path('seeders/samples/lms/lecture-2-gas-laws.docx'), 'renamed.doc');
        $this->assertSame('sci-docx', $import->format); // read as the Word file it really is

        $xlsx = tempnam(sys_get_temp_dir(), 'q').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($xlsx, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Q" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Question</t></si><si><t>Option A</t></si><si><t>Option B</t></si><si><t>Answer</t></si><si><t>Gas plants absorb?</t></si><si><t>Oxygen</t></si><si><t>Carbon dioxide</t></si><si><t>B</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" t="s"><v>5</v></c><c r="C2" t="s"><v>6</v></c><c r="D2" t="s"><v>7</v></c></row></sheetData></worksheet>');
        $zip->close();
        $import = $this->upload($xlsx, 'questions.xlsx');
        $this->assertSame(['xlsx', 'parsed', 1], [$import->format, $import->status, $import->question_count]);
    }

    public function test_a_refused_file_says_why_in_plain_words(): void
    {
        $exam = $this->cbtExam(['status' => 'draft'], []);
        $section = $exam->sections()->first() ?? $exam->sections()->create(['title' => 'Section A', 'position' => 0]);
        $this->actingAs($this->officer)->post('/question-bank/import', ['file' => UploadedFile::fake()->create('paper.ppt', 10), 'section_id' => $section->id])
            ->assertSessionHasErrors(['file' => 'Upload a Word file (.docx or .doc), a PDF, an Excel sheet (.xlsx), a CSV or a text file.']);
    }
}
