<?php

namespace Tests\Feature\Exams;

use App\Models\ProctoringEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** The checks chosen for an exam must all pass before it starts; a room scan comes before the paper. */
class PreChecksTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_the_exam_cannot_start_until_every_chosen_check_has_passed(): void
    {
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => false, 'webcam' => true, 'microphone' => true, 'pre_checks' => ['camera', 'microphone', 'keyboard', 'network']]]);
        $c = $this->candidate($this->sitting($exam));

        $this->startAs($c)->assertStatus(422)->assertJsonPath('reason', 'pre_checks')->assertJsonPath('missing', ['network', 'keyboard', 'camera', 'microphone']);
        $this->startAs($c, ['checks' => ['camera' => true, 'microphone' => false, 'keyboard' => true, 'network' => true]])->assertStatus(422)->assertJsonPath('missing', ['microphone']);

        $this->startAs($c, ['checks' => ['camera' => true, 'microphone' => true, 'keyboard' => true, 'network' => true], 'check_details' => ['network' => '40 ms']])->assertOk();
        $this->assertSame(1, ProctoringEvent::where('type', 'pre_checks_passed')->count());

        // coming back to an exam under way does not repeat them
        $this->startAs($c)->assertOk()->assertJsonPath('resumed', true);
    }

    public function test_no_checks_chosen_means_none_are_asked(): void
    {
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => false, 'pre_checks' => []]]);
        $this->startAs($this->candidate($this->sitting($exam)))->assertOk();
    }

    public function test_the_paper_opens_only_after_the_room_scan_has_arrived(): void
    {
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => false, 'webcam' => true, 'pre_checks' => ['room_scan']]]);
        $token = $this->startAs($this->candidate($this->sitting($exam)))->assertOk()->json('token');

        $this->api($token, 'GET', 'paper')->assertStatus(428)->assertJsonPath('reason', 'room_scan');
        $this->withHeaders(['Authorization' => "Bearer $token"])->post('/api/exam/attempt/evidence', [
            'kind' => 'room_scan', 'file' => UploadedFile::fake()->create('room.webm', 300, 'video/webm'),
        ])->assertSuccessful();
        $this->api($token, 'GET', 'paper')->assertOk();
    }

    public function test_the_checklist_is_saved_per_exam(): void
    {
        $exam = $this->cbtExam(['status' => 'draft']);
        $this->actingAs($this->officer)->put("/exams/{$exam->id}", ['proctoring' => ['pre_checks' => ['camera', 'secure_app', 'nonsense']]])->assertSessionHasNoErrors();
        $exam->refresh();
        $this->assertSame(['camera', 'secure_app'], $exam->proctoring['pre_checks']);
        // the secure app check means the exam is taken in the desktop app or Safe Exam Browser only
        $this->assertEqualsCanonicalizing(['desktop', 'seb'], $exam->channels);
        $this->actingAs($this->officer)->get("/exams/{$exam->id}")->assertInertia(fn ($p) => $p->has('exam.pre_check_options', 14));
    }
}
