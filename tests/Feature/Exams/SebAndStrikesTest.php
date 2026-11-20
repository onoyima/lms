<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\User;
use App\Services\Exams\SebConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Safe Exam Browser from the institution's own server, protected by the school's SEB password, with
 * notifications kept out; and strikes: leaving the exam screen is a warning, and at the limit the
 * exam locks until someone unlocks it.
 */
class SebAndStrikesTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_the_school_seb_password_encrypts_every_settings_file(): void
    {
        $exam = $this->cbtExam(['channels' => ['seb']]);
        $c = $this->candidate($this->sitting($exam));

        // no password yet: plain settings, with notifications kept out on every system
        $plain = $this->actingAs($c->user)->get("/my/exams/{$c->id}/seb")->assertOk()->getContent();
        foreach (['enableMacOSAAC', 'mobileEnableASAM', 'createNewDesktop', 'allowSiri'] as $key) {
            $this->assertStringContainsString("<key>$key</key>", $plain);
        }

        $this->actingAs($this->officer)->put('/admin/safe-exam-browser/passwords', ['settings_password' => 'Veritas#SEB1', 'quit_password' => 'leave-now'])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('Veritas#SEB1', json_encode($this->tenant->fresh()->settings));

        $file = $this->actingAs($c->user)->get("/my/exams/{$c->id}/seb")->assertOk()->getContent();
        $this->assertStringNotContainsString('startURL', $file);
        $this->assertNull(SebConfig::open($file, 'wrong'));
        $xml = SebConfig::open($file, 'Veritas#SEB1');
        $this->assertStringContainsString('<key>startURL</key>', $xml);
        $this->assertStringContainsString(hash('sha256', 'Veritas#SEB1'), $xml);   // SEB's own settings are locked too
        $this->assertStringContainsString(hash('sha256', 'leave-now'), $xml);

        // an exam can have its own password; it is never sent back to the browser
        $this->actingAs($this->officer)->put("/exams/{$exam->id}", ['seb' => ['settings_password' => 'OnlyThisExam']])->assertSessionHasNoErrors();
        $this->assertNotNull(SebConfig::open($this->actingAs($c->user)->get("/my/exams/{$c->id}/seb")->getContent(), 'OnlyThisExam'));
        $this->actingAs($this->officer)->get("/exams/{$exam->id}")->assertInertia(fn ($p) => $p->where('exam.settings.seb.has_settings_password', true)->missing('exam.settings.seb.settings_password'));
    }

    public function test_installers_are_served_from_this_server(): void
    {
        Storage::fake('local');
        $student = User::factory()->create(['user_type' => 'student']);
        $this->actingAs($student)->get('/safe-exam-browser')->assertOk()->assertInertia(fn ($p) => $p->where('systems.0.file', null));
        $this->actingAs($student)->get('/admin/safe-exam-browser')->assertForbidden();

        $this->actingAs($this->officer)->post('/admin/safe-exam-browser/installers', ['os' => 'windows', 'file' => UploadedFile::fake()->create('virus.dmg', 10)])->assertSessionHasErrors('file');
        $this->actingAs($this->officer)->post('/admin/safe-exam-browser/installers', ['os' => 'windows', 'version' => '3.8', 'file' => UploadedFile::fake()->create('SEB_3.8_Setup.exe', 200)])->assertSessionHasNoErrors();

        $this->actingAs($student)->get('/safe-exam-browser')->assertInertia(fn ($p) => $p->where('systems.0.file.name', 'SEB_3.8_Setup.exe')->where('systems.0.file.version', '3.8'));
        $this->actingAs($student)->get('/safe-exam-browser/windows/download')->assertOk()->assertDownload('SEB_3.8_Setup.exe');
        $this->actingAs($student)->get('/safe-exam-browser/macos/download')->assertNotFound();

        $this->actingAs($this->officer)->delete('/admin/safe-exam-browser/installers/windows')->assertSessionHasNoErrors();
        $this->actingAs($student)->get('/safe-exam-browser/windows/download')->assertNotFound();
    }

    public function test_leaving_the_screen_five_times_locks_the_exam_until_unlocked(): void
    {
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => false, 'violation_threshold' => 5, 'violation_action' => 'lock', 'detect_focus_loss' => true]]);
        $c = $this->candidate($this->sitting($exam));
        $token = $this->startAs($c)->json('token');
        $this->api($token, 'GET', 'paper')->assertOk();

        // a second event in the same departure is sent as focus_lost: logged, not counted
        $this->api($token, 'POST', 'heartbeat', ['status' => 'active', 'events' => [['type' => 'screen_left'], ['type' => 'focus_lost']]])->assertJsonPath('attempt.violations', 1);
        foreach (range(2, 4) as $n) {
            $this->api($token, 'POST', 'heartbeat', ['status' => 'active', 'events' => [['type' => $n % 2 ? 'screen_left' : 'tab_hidden']]])
                ->assertJsonPath('attempt.violations', $n)->assertJsonPath('attempt.status', 'in_progress')->assertJsonPath('attempt.violation_threshold', 5);
        }
        $this->api($token, 'POST', 'heartbeat', ['status' => 'active', 'events' => [['type' => 'fullscreen_exit']]])->assertJsonPath('attempt.status', 'locked');

        // locked: nothing can be written until an invigilator or administrator unlocks it; answers stay queued on the computer
        $q = $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions.0');
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q['id'], 'seq' => 1, 'response' => ['options' => [$q['options'][0]['id']]]]]])->assertStatus(423);

        app(\App\Services\Exams\AttemptService::class)->resume(Attempt::first(), $this->officer->id);
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q['id'], 'seq' => 1, 'response' => ['options' => [$q['options'][0]['id']]]]]])->assertOk();
    }
}
