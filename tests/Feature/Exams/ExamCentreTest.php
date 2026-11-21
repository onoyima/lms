<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\ExamCentre;
use App\Models\ExamSession;
use App\Models\Question;
use App\Models\Result;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Exams\CentrePackages;
use App\Services\Exams\PaperBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** O2: a sitting goes to an exam centre without internet as a signed package; the scripts come back and are marked on the main server. */
class ExamCentreTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_a_sitting_runs_at_a_centre_and_its_scripts_are_marked_at_the_main_server(): void
    {
        // main server: a centre and its key, an exam with a shuffled paper, a candidate
        $this->actingAs($this->officer)->post('/exam-centres', ['name' => 'Enugu centre', 'code' => 'enu', 'role' => 'centre'])->assertSessionHas('centre_key');
        $key = session('centre_key')['key'];
        $centre = ExamCentre::where('code', 'ENU')->sole();
        $this->assertNotSame($key, DB::table('exam_centres')->value('secret')); // stored encrypted
        [$q1, $q2] = $this->questions();
        $exam = $this->cbtExam(['settings' => ['shuffle_questions' => true, 'shuffle_options' => true, 'require_check_in' => false]], [$q1, $q2]);
        $session = $this->sitting($exam);
        $student = User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/ENU/1', 'name' => 'Ngozi Centre']);
        $this->candidate($session, $student);

        $package = $this->get("/sessions/{$session->id}/centre-package?centre_id={$centre->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('Balance', $package); // packed, not readable as plain text
        $mainTenant = $this->tenant;

        // the centre's own server (another institution record here): the main server's key, then the package
        $centreTenant = app(\App\Services\Tenancy\TenantProvisioner::class)->provision(['slug' => 'enugu-centre', 'name' => 'Enugu centre'], 'enterprise');
        app(TenantContext::class)->set($centreTenant);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($centreTenant->id);
        $main = ExamCentre::create(['name' => 'Main server', 'code' => 'ENU', 'role' => 'main', 'secret' => $key]);
        $wrong = ExamCentre::create(['name' => 'Other', 'code' => 'XYZ', 'role' => 'main', 'secret' => str_repeat('x', 48)]);
        $packages = app(CentrePackages::class);
        try {
            $packages->readSitting($package, $wrong, $this->officer);
            $this->fail('a package signed with another key must be refused');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('signed with another', $e->getMessage());
        }
        $local = $packages->readSitting($package, $main, $this->officer);
        $this->assertSame(1, $local->candidates()->count());
        $localStudent = User::where('identifier', 'VUG/ENU/1')->sole();
        $this->assertSame($student->password, $localStudent->password); // the same password at the centre

        // the student writes at the centre: right answer to the single-choice question only
        $attempt = Attempt::forceCreate(['tenant_id' => $centreTenant->id, 'exam_id' => $local->exam_id, 'exam_session_id' => $local->id, 'exam_candidate_id' => $local->candidates()->value('id'),
            'user_id' => $localStudent->id, 'attempt_no' => 1, 'status' => 'in_progress', 'channel' => 'web', 'started_at' => now()->subMinutes(20), 'deadline_at' => now()->addMinutes(10), 'token_hash' => 'x']);
        app(PaperBuilder::class)->build($attempt);
        $localQ1 = Question::where('centre_ref', 'm'.$mainTenant->id.'q'.$q1->id)->sole();
        DB::table('attempt_answers')->insert(['attempt_id' => $attempt->id, 'question_id' => $localQ1->id, 'response' => json_encode(['options' => [$localQ1->options()->where('is_correct', true)->value('id')]]), 'client_seq' => 1]);
        $attempt->update(['status' => 'submitted', 'submitted_at' => now()]);
        $scripts = $packages->packResults($local, $main);

        // back on the main server: the scripts are added once and marked with the main server's key
        app(TenantContext::class)->set($mainTenant);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($mainTenant->id);
        $file = UploadedFile::fake()->createWithContent('results.cbtpkg', $scripts);
        $this->post("/sessions/{$session->id}/centre-results", ['centre_id' => $centre->id, 'file' => $file])->assertSessionHas('success', fn ($m) => str_starts_with($m, '1 scripts added and marked'));
        $this->post("/sessions/{$session->id}/centre-results", ['centre_id' => $centre->id, 'file' => UploadedFile::fake()->createWithContent('r.cbtpkg', $scripts)])->assertSessionHas('success', fn ($m) => str_contains($m, '1 were already here'));
        $mainAttempt = Attempt::where('user_id', $student->id)->sole();
        $this->assertSame('centre', $mainAttempt->channel);
        $this->assertSame(1.0, (float) $mainAttempt->score_raw); // 1 of 3 marks
        $this->assertNotNull(Result::where('exam_id', $exam->id)->where('user_id', $student->id)->value('raw_score'));

        // a changed file is refused
        $tampered = json_decode($scripts, true);
        $tampered['payload'] = base64_encode(gzencode(str_replace('"submitted"', '"terminated"', gzdecode(base64_decode($tampered['payload'])))));
        $this->post("/sessions/{$session->id}/centre-results", ['centre_id' => $centre->id, 'file' => UploadedFile::fake()->createWithContent('t.cbtpkg', json_encode($tampered))])->assertStatus(422);
        $this->get('/exam-centres')->assertOk();
    }
}
