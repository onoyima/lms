<?php

namespace Tests\Feature\Exams;

use App\Models\ExamCandidate;
use App\Models\ExamGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Students onto an exam or a category: uploaded lists (CSV, Excel, text), one by one, by matric prefix or e-mail domain. */
class ExamStudentsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $strings = [];
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach ($row as $c => $v) {
                $ref = chr(65 + $c).($r + 1);
                if (is_int($v)) {
                    $xml .= "<c r=\"$ref\"><v>$v</v></c>";
                } else {
                    $strings[] = $v;
                    $xml .= "<c r=\"$ref\" t=\"s\"><v>".(count($strings) - 1).'</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s).'</t></si>', $strings)).'</sst>');
        $zip->close();

        return $path;
    }

    public function test_an_uploaded_csv_previews_then_adds_known_and_new_students_to_a_sitting(): void
    {
        $session = $this->sitting($this->cbtExam());
        $known = User::factory()->create(['user_type' => 'student', 'identifier' => 'MAT/2026/001', 'email' => 'jane@uni.edu']);
        $csv = "First Name,Middle Name,Surname,E-mail,Matric No,Password\nJane,,Doe,jane@uni.edu,MAT/2026/001,\nJohn,Robert,Smith,,MAT/2026/002,Secret99\nAda,,Obi,ada@uni.edu,,\n,,,,,\nBad,,Row,not-an-email,,\n";

        $preview = $this->actingAs($this->officer)->post('/student-lists/preview', ['file' => UploadedFile::fake()->createWithContent('class.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('total', 3)->assertJsonPath('existing', 1)->assertJsonPath('new', 2)->assertJsonPath('invalid.0.reason', '"not-an-email" is not an e-mail address')->json();
        $this->assertSame(1, User::where('user_type', 'student')->count()); // nothing made yet

        $this->post("/sessions/{$session->id}/candidates", ['list_token' => $preview['token'], 'create_missing' => true, 'password_rule' => 'file_or_matric'])->assertSessionHas('success');
        $this->assertSame(3, ExamCandidate::where('exam_session_id', $session->id)->count());
        $john = User::where('identifier', 'MAT/2026/002')->sole();
        $this->assertSame('John Robert Smith', $john->name);
        $this->assertTrue(Hash::check('Secret99', $john->password));
        $this->assertStringEndsWith('@students.invalid', $john->email);
        $this->assertTrue($john->hasRole('student'));
        $this->assertTrue(Hash::check('ada@uni.edu', User::where('email', 'ada@uni.edu')->sole()->password)); // no matric: the e-mail
        $this->assertSame($known->id, ExamCandidate::where('exam_session_id', $session->id)->orderBy('id')->first()->user_id);
    }

    public function test_excel_and_plain_text_lists_and_existing_accounts_only(): void
    {
        $session = $this->sitting($this->cbtExam());
        User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/1']);
        $path = $this->xlsx([['matric_no', 'lastname', 'firstname', 'phone'], ['VUG/1', 'Eze', 'Ngozi', 8031234567], ['VUG/2', 'Bello', 'Musa', 8030000000]]);
        $preview = $this->actingAs($this->officer)->post('/student-lists/preview', ['file' => new UploadedFile($path, 'class.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('sample.1.name', 'Musa Bello')->json();
        // without "make accounts", only students who already have one are added
        $this->post("/sessions/{$session->id}/candidates", ['list_token' => $preview['token'], 'create_missing' => false])->assertSessionHas('success', fn ($m) => str_contains($m, '1 candidates added') && str_contains($m, 'VUG/2'));

        $txt = $this->post('/student-lists/preview', ['file' => UploadedFile::fake()->createWithContent('list.txt', "VUG/1\nVUG/3\nsomeone@uni.edu\n")], ['Accept' => 'application/json'])->json();
        $this->assertSame([3, 1], [$txt['total'], $txt['existing']]);
        $this->post("/sessions/{$session->id}/candidates", ['list_token' => $txt['token'], 'create_missing' => true, 'password_rule' => 'surname'])->assertSessionHas('success');
        $this->assertSame(3, ExamCandidate::where('exam_session_id', $session->id)->count());
    }

    public function test_one_by_one_by_prefix_or_domain_and_removing_one(): void
    {
        $session = $this->sitting($this->cbtExam());
        $a = User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/NSC/22/0001', 'name' => 'Amaka Nwosu']);
        User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/NSC/22/0002']);
        User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/LAW/22/0003', 'email' => 'law@students.uni.edu']);
        $staff = User::factory()->create(['user_type' => 'staff', 'name' => 'Amaka Staff']);

        $this->actingAs($this->officer)->getJson('/student-search?q=amaka')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $a->id);
        $this->post("/sessions/{$session->id}/candidates", ['user_ids' => [$a->id]])->assertSessionHas('success');
        $this->post("/sessions/{$session->id}/candidates", ['user_ids' => [$staff->id]])->assertStatus(422);
        $this->post("/sessions/{$session->id}/candidates", ['identifier_prefix' => 'VUG/NSC/22/'])->assertSessionHas('success', fn ($m) => str_starts_with($m, '1 candidates added'));
        $this->post("/sessions/{$session->id}/candidates", ['email_domain' => '@students.uni.edu']);
        $this->assertSame(3, ExamCandidate::where('exam_session_id', $session->id)->count());

        $c = ExamCandidate::where('user_id', $a->id)->sole();
        $this->delete("/sessions/{$session->id}/candidates/{$c->id}")->assertSessionHas('success');
        $this->assertSame(2, ExamCandidate::where('exam_session_id', $session->id)->count());
        // a student who has started stays
        $started = ExamCandidate::where('exam_session_id', $session->id)->first();
        $this->startAs($started)->assertOk();
        $this->actingAs($this->officer)->delete("/sessions/{$session->id}/candidates/{$started->id}")->assertStatus(422);
    }

    public function test_an_uploaded_list_goes_to_a_whole_category(): void
    {
        $group = ExamGroup::create(['title' => 'Nursing Professional Exam', 'created_by' => $this->officer->id]);
        $exam = $this->cbtExam(['exam_group_id' => $group->id]);
        $this->sitting($exam);
        $preview = $this->actingAs($this->officer)->post('/student-lists/preview', ['file' => UploadedFile::fake()->createWithContent('c.csv', "matric_no,name\nNUR/1,Ife Ade\nNUR/2,Tobi Ola\n")], ['Accept' => 'application/json'])->json();
        $this->post("/exam-groups/{$group->id}/students", ['target' => 'category', 'source' => 'upload', 'list_token' => $preview['token'], 'create_missing' => true])
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, '2 students added to the category'));
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('exam_group_students')->where('exam_group_id', $group->id)->count());
        $this->assertSame(2, ExamCandidate::count());
        $this->get('/templates/exam_students')->assertOk();
    }
}
