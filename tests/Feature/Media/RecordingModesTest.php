<?php

namespace Tests\Feature\Media;

use App\Models\Attempt;
use App\Services\Media\BrowserRecordings;
use App\Support\Exams\ExamSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/** Institutions choose how recordings are made: live streaming, uploaded as it goes, after submitting, or clips on events. */
class RecordingModesTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.livekit' => ['url' => 'wss://media.test', 'api_url' => 'https://media.test', 'key' => 'APIkey1', 'secret' => str_repeat('s', 40), 'recordings_path' => '/recordings']]);
        $this->setUpPlatform();
    }

    private function token(array $p): string
    {
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => false, 'live_video' => true, 'record_video' => true] + $p]);

        return $this->startAs($this->candidate($this->sitting($exam)))->json('token');
    }

    public function test_the_institution_default_records_in_the_browser_with_no_live_video(): void
    {
        $this->api($this->token([]), 'POST', 'media-token')->assertOk()->assertJson(['enabled' => false, 'record' => ['camera' => true, 'mode' => 'progressive']]);
    }

    public function test_live_streaming_sends_video_to_the_media_server(): void
    {
        $this->api($this->token(['recording_mode' => 'stream']), 'POST', 'media-token')->assertJson(['enabled' => true, 'record' => ['mode' => 'stream']]);
    }

    public function test_the_institution_can_change_its_default_and_an_exam_can_override_it(): void
    {
        $this->tenant->update(['settings' => array_replace_recursive((array) $this->tenant->settings, ['policy' => ['recording_mode' => 'triggered']])]);
        $this->api($this->token([]), 'POST', 'media-token')->assertJson(['record' => ['mode' => 'triggered']]);
        $this->assertSame('on_submit', ExamSettings::recordingMode(['recording_mode' => 'on_submit']));
    }

    public function test_pieces_uploaded_after_submitting_are_accepted_for_a_day(): void
    {
        $t = $this->token(['recording_mode' => 'on_submit']);
        $this->api($t, 'POST', 'submit')->assertOk();
        $a = Attempt::first();
        $this->travel(5)->hours();
        $this->assertTrue(app(BrowserRecordings::class)->accepts($a->fresh()));
        $this->travel(20)->hours();
        $this->assertFalse(app(BrowserRecordings::class)->accepts($a->fresh()));
    }
}
