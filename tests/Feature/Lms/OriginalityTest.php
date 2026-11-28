<?php

namespace Tests\Feature\Lms;

use App\Models\AcademicSession;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\Enrolment;
use App\Models\Semester;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Lms\DocumentText;
use App\Services\Lms\PdfText;
use App\Services\Lms\Similarity;
use App\Services\Lms\WritingCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/**
 * Every hand-in is read (Word, PDF, slides, photos), scored for similarity to other students' work
 * and for AI writing, and both scores are shown to the student and the lecturer. The lecturer also
 * sees why, and can check again.
 */
class OriginalityTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private const AI_TEXT = "In today's ever-evolving world, technology plays a crucial role in shaping education. Furthermore, it is important to note that digital tools foster collaboration and seamless communication among students. Moreover, these innovations underscore the transformative potential of learning platforms. Additionally, educators can leverage robust systems to deliver a comprehensive understanding of complex topics. In addition, the multifaceted landscape of modern teaching requires a holistic approach that is both nuanced and meticulous. Furthermore, institutions must navigate the complexities of implementation with care and foresight. Moreover, the integration of technology serves as a testament to human ingenuity and progress. Additionally, students benefit from personalised pathways that unlock their full potential in every subject. Overall, the realm of education stands as a vibrant tapestry of opportunity and growth. In conclusion, embracing these cutting-edge tools is paramount for fostering success in the classroom of tomorrow.";

    private const HUMAN_TEXT = "We did the titration twice. The first run went wrong because Chidi forgot to rinse the burette, so the reading was off by almost 2 ml and we had to start again, which took most of the period. Second time it worked. The end point came at 23.4 ml and the pink colour stayed for about thirty seconds, which Mrs Okafor said was fine. I think our concentration came out a bit high (0.104 mol/dm3 instead of 0.1) maybe because I added the indicator late or the flask was still wet from washing. Next time I'd swirl more slowly near the end. Also the white tile helped a lot to see the change! Honestly the hardest part was reading the meniscus at eye level since the bench was too low for me and I kept bending. Our group got a mean titre of 23.35 ml from the two good runs. If we had more time we would have done a third run to be sure the results were concordant, but the bell went.";

    private User $lecturer;

    private CourseOffering $o;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        Storage::fake('local');
        $sem = Semester::create(['academic_session_id' => AcademicSession::create(['name' => '2026/2027', 'is_current' => true])->id, 'name' => 'First', 'is_current' => true]);
        $this->lecturer = User::factory()->create(['user_type' => 'staff']);
        $this->lecturer->assignRole('lecturer');
        $this->o = CourseOffering::create(['course_id' => Course::create(['code' => 'CHM 102', 'title' => 'Chemistry', 'credit_units' => 3, 'level' => 100])->id, 'semester_id' => $sem->id, 'status' => 'open']);
        $this->o->staff()->attach($this->lecturer->id, ['role' => 'coordinator']);
    }

    private function student(): User
    {
        $u = User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/'.fake()->unique()->numerify('#####')]);
        $u->assignRole('student');
        Enrolment::create(['course_offering_id' => $this->o->id, 'user_id' => $u->id, 'status' => 'active']);

        return $u;
    }

    private function assignment(): CourseItem
    {
        $section = CourseSection::firstOrCreate(['course_offering_id' => $this->o->id, 'title' => 'Week 1']);

        return CourseItem::create(['course_offering_id' => $this->o->id, 'course_section_id' => $section->id, 'type' => 'assignment', 'title' => 'Lab report',
            'visible' => true, 'due_at' => now()->addDays(3), 'settings' => ['max_score' => 20, 'file_types' => ['docx', 'pdf', 'txt', 'png', 'jpg'], 'max_mb' => 50]]);
    }

    private function pdf(string $text): string
    {
        $d = new \Dompdf\Dompdf;
        $d->loadHtml('<h1>Report</h1><p>'.e($text).'</p><p><b>Signed</b> <i>by the student</i></p>');
        $d->render();

        return (string) $d->output();
    }

    public function test_ai_style_writing_scores_higher_than_a_students_own_writing(): void
    {
        $check = app(WritingCheck::class);
        $ai = $check->measure(self::AI_TEXT);
        $own = $check->measure(self::HUMAN_TEXT);

        $this->assertSame('high', WritingCheck::band($ai['score']));
        $this->assertSame('low', WritingCheck::band($own['score']));
        $this->assertNotEmpty(array_filter($ai['signals'], fn ($s) => str_contains($s['label'], 'phrases')));
        $this->assertNull($check->measure('Too short to judge.')['score']);
    }

    public function test_pdf_text_is_read_without_pdftotext(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $this->pdf('Oxygen bubbles doubled at 10 cm, café.'));
        $text = preg_replace('/\s+/', ' ', (new PdfText)->read($path));
        $this->assertStringContainsString('Oxygen bubbles doubled at 10 cm, café.', $text);
        $this->assertStringContainsString('Signed by the student', $text);
        unlink($path);
    }

    public function test_hand_ins_are_checked_and_both_sides_see_the_scores(): void
    {
        $item = $this->assignment();
        [$a, $b] = [$this->student(), $this->student()];
        $this->actingAs($a)->post("/learn/items/{$item->id}/submit", ['file' => UploadedFile::fake()->createWithContent('report.pdf', $this->pdf(self::HUMAN_TEXT))])->assertSessionHasNoErrors();
        $this->actingAs($b)->post("/learn/items/{$item->id}/submit", ['text' => '<p>'.self::HUMAN_TEXT.'</p>'])->assertSessionHasNoErrors();
        $sim = app(Similarity::class);
        foreach (AssignmentSubmission::orderBy('id')->get() as $s) {
            $sim->run($s);
        }

        $sb = AssignmentSubmission::where('user_id', $b->id)->sole();
        $this->assertSame('read', $sb->text_status);
        $this->assertGreaterThan(60, (float) $sb->similarity); // copied from the PDF
        $this->assertNotNull($sb->ai_score);

        // the student sees both scores, not the reasons or who they match
        $this->actingAs($b)->get("/learn/items/{$item->id}")->assertInertia(fn ($p) => $p
            ->where('submissions.0.checks.checked', true)
            ->where('submissions.0.checks.similarity', fn ($v) => $v > 60)
            ->where('submissions.0.checks.ai_band', 'low')
            ->where('submissions.0.checks.ai_signals', []));
        // the lecturer sees the scores and the reasons
        $this->actingAs($this->lecturer)->get("/learn/items/{$item->id}")->assertInertia(fn ($p) => $p
            ->where('submissions.data.1.checks.similarity', fn ($v) => $v > 60)
            ->where('submissions.data.1.checks.ai_signals', fn ($v) => count($v) > 0));

        // only the lecturer can check again
        $this->actingAs($b)->post("/learn/submissions/{$sb->id}/recheck")->assertForbidden();
        $this->actingAs($this->lecturer)->post("/learn/submissions/{$sb->id}/recheck")->assertSessionHas('success');
    }

    public function test_a_photo_without_readable_words_is_marked_not_checkable(): void
    {
        $item = $this->assignment();
        $s = $this->student();
        $img = imagecreatetruecolor(200, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);
        $this->actingAs($s)->post("/learn/items/{$item->id}/submit", ['file' => UploadedFile::fake()->createWithContent('work.png', (string) ob_get_clean())])->assertSessionHasNoErrors();
        $sub = AssignmentSubmission::sole();
        app(Similarity::class)->run($sub);
        $this->assertSame('unreadable', $sub->fresh()->text_status);
        $this->assertNull($sub->fresh()->ai_score);
    }

    public function test_with_ai_set_up_the_model_judgement_counts_and_its_reasons_reach_the_lecturer(): void
    {
        $this->tenant->update(['settings' => array_merge((array) $this->tenant->settings, ['ai' => ['provider' => 'anthropic', 'key' => AiSettings::seal('sk-test'), 'features' => ['check_writing' => true]]])]);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"ai_likelihood": 90, "reasons": ["Generic statements with no detail from the lab"]}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]])]);

        $item = $this->assignment();
        $s = $this->student();
        $this->actingAs($s)->post("/learn/items/{$item->id}/submit", ['text' => self::HUMAN_TEXT])->assertSessionHasNoErrors();
        $sub = AssignmentSubmission::sole();
        app(Similarity::class)->run($sub);
        $sub->refresh();

        $this->assertGreaterThan(60, (float) $sub->ai_score); // 0.7 × 90 + 0.3 × local
        $this->assertSame(AiSettings::DEFAULT_MODEL['anthropic'], data_get($sub->ai_signals, 'model'));
        $this->actingAs($this->lecturer)->get("/learn/items/{$item->id}")->assertInertia(fn ($p) => $p
            ->where('submissions.data.0.checks.ai_signals', fn ($v) => collect($v)->contains('label', 'Generic statements with no detail from the lab')));
    }

    public function test_slides_and_open_documents_are_read(): void
    {
        $dir = sys_get_temp_dir().'/odoc'.uniqid();
        mkdir($dir);
        $pptx = "$dir/talk.pptx";
        $z = new \ZipArchive;
        $z->open($pptx, \ZipArchive::CREATE);
        $z->addFromString('ppt/slides/slide1.xml', '<p:sld><a:p><a:r><a:t>Ohm law slide</a:t></a:r></a:p></p:sld>');
        $z->addFromString('ppt/slides/slide2.xml', '<p:sld><a:p><a:r><a:t>Resistors in series</a:t></a:r></a:p></p:sld>');
        $z->close();
        $odt = "$dir/essay.odt";
        $z = new \ZipArchive;
        $z->open($odt, \ZipArchive::CREATE);
        $z->addFromString('content.xml', '<office:document-content><text:p>Kirchhoff current law</text:p></office:document-content>');
        $z->close();

        $reader = new DocumentText;
        $this->assertStringContainsString('Ohm law slide', $reader->read($pptx, 'talk.pptx'));
        $this->assertStringContainsString('Resistors in series', $reader->read($pptx, 'talk.pptx'));
        $this->assertStringContainsString('Kirchhoff current law', $reader->read($odt, 'essay.odt'));
    }
}
