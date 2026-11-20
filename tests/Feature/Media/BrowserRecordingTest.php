<?php

namespace Tests\Feature\Media;

use App\Models\Attempt;
use App\Models\Incident;
use App\Models\MediaRecording;
use App\Models\RecordingChunk;
use App\Services\Media\BrowserRecordings;
use App\Services\Media\WebmDuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/** Recording in the candidate's browser when there is no media server (shared hosting). */
class BrowserRecordingTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.livekit.url' => '', 'platform.recording.disk' => 'local']);
        Storage::fake('local');
    }

    /** A tiny WebM laid out like a browser writes it: header, segment of unknown size, Info, then a cluster. */
    private function webmStart(): string
    {
        $ebml = "\x1A\x45\xDF\xA3"."\x84"."\x42\x86\x81\x01"; // EBMLVersion 1
        $info = "\x2A\xD7\xB1\x83\x0F\x42\x40"; // TimecodeScale 1,000,000
        $info .= "\x4D\x80\x84Test";
        $segment = "\x18\x53\x80\x67"."\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF";
        $cluster = "\x1F\x43\xB6\x75"."\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"."\xE7\x81\x00".str_repeat('V', 50);

        return $ebml.$segment."\x15\x49\xA9\x66".chr(0x80 | strlen($info)).$info.$cluster;
    }

    private function piece(string $bytes, string $name = 'piece.webm'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function send(string $token, array $data, string $bytes)
    {
        return $this->withHeaders(['Authorization' => "Bearer $token"])
            ->post('/api/exam/attempt/recording', $data + ['file' => $this->piece($bytes)], ['Accept' => 'application/json']);
    }

    private function recordingExam(array $p = [])
    {
        return $this->cbtExam(['proctoring' => array_merge(['require_consent' => false, 'webcam' => true, 'record_video' => true, 'microphone' => true], $p)]);
    }

    public function test_without_a_media_server_the_browser_is_told_to_record(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->recordingExam(['screen_share' => true, 'record_screen' => true])));
        $token = $this->startAs($c)->json('token');

        $this->api($token, 'POST', 'media-token')->assertOk()
            ->assertJson(['enabled' => false, 'record' => ['camera' => true, 'screen' => true, 'audio' => true]]);

        // an exam that records nothing gets no plan
        $plain = $this->candidate($this->sitting($this->cbtExam(['proctoring' => ['require_consent' => false, 'webcam' => true]])));
        $t2 = $this->startAs($plain)->json('token');
        $this->assertNull($this->api($t2, 'POST', 'media-token')->json('record'));
    }

    public function test_pieces_are_stored_once_encrypted_and_joined_for_proctors(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->recordingExam()));
        $token = $this->startAs($c)->json('token');
        $key = str_repeat('a1', 10);

        $this->send($token, ['key' => $key, 'kind' => 'camera', 'seq' => 0, 'offset_ms' => 10000], $this->webmStart())->assertOk();
        $this->send($token, ['key' => $key, 'kind' => 'camera', 'seq' => 0, 'offset_ms' => 10000], $this->webmStart())->assertOk(); // sent twice after a dropped connection
        $this->send($token, ['key' => $key, 'kind' => 'camera', 'seq' => 1, 'offset_ms' => 20000], 'SECOND-PIECE-BYTES')->assertOk();
        $this->send($token, ['key' => $key, 'kind' => 'camera', 'seq' => 2, 'offset_ms' => 25000, 'final' => 1], 'LAST')->assertOk()->assertJson(['status' => 'complete']);

        $rec = MediaRecording::sole();
        $this->assertSame('camera', $rec->kind);
        $this->assertSame('complete', $rec->status);
        $this->assertSame(3, RecordingChunk::count());
        $this->assertSame(25, $rec->duration_seconds);

        // encrypted at rest: the stored file is not the bytes that were sent
        $stored = Storage::disk('local')->get(RecordingChunk::where('seq', 1)->value('path'));
        $this->assertStringNotContainsString('SECOND-PIECE-BYTES', $stored);

        $res = $this->actingAs($this->officer)->get("/proctor/recordings/{$rec->id}")->assertOk();
        $this->assertSame('video/webm', $res->headers->get('Content-Type'));
        $body = file_get_contents($res->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString("\x44\x89\x88", $body); // the length was written into the file
        $this->assertStringEndsWith('SECOND-PIECE-BYTESLAST', $body);
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'evidence.recording_viewed')->count());

        // the candidate cannot play it
        $this->actingAs($c->user)->get("/proctor/recordings/{$rec->id}")->assertForbidden();
    }

    public function test_only_what_the_exam_records_is_accepted(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->recordingExam()));
        $token = $this->startAs($c)->json('token');

        $this->send($token, ['key' => str_repeat('b', 20), 'kind' => 'screen', 'seq' => 0], 'x')->assertForbidden();
        $this->send($token, ['key' => 'short', 'kind' => 'camera', 'seq' => 0], 'x')->assertStatus(422);
        $this->assertSame(0, MediaRecording::count());
    }

    public function test_late_pieces_arrive_after_submitting_but_not_hours_later(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->recordingExam()));
        $token = $this->startAs($c)->json('token');
        $this->api($token, 'POST', 'submit')->assertOk();

        $this->send($token, ['key' => str_repeat('c', 20), 'kind' => 'camera', 'seq' => 0], $this->webmStart())->assertOk();

        Attempt::query()->update(['submitted_at' => now()->subHours(3)]);
        $this->send($token, ['key' => str_repeat('c', 20), 'kind' => 'camera', 'seq' => 1], 'late')->assertStatus(409);
    }

    public function test_quiet_recordings_finish_and_old_ones_are_deleted_unless_an_incident_is_open(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->recordingExam()));
        $token = $this->startAs($c)->json('token');
        $this->send($token, ['key' => str_repeat('d', 20), 'kind' => 'camera', 'seq' => 0], $this->webmStart())->assertOk();
        $svc = app(BrowserRecordings::class);

        MediaRecording::query()->update(['updated_at' => now()->subMinutes(30)]);
        $this->assertSame(1, $svc->sweep());
        $this->assertSame('complete', MediaRecording::sole()->status);
        $this->assertSame(0, $svc->verify(MediaRecording::sole()));

        MediaRecording::query()->update(['created_at' => now()->subDays(400)]);
        $incident = Incident::create(['attempt_id' => Attempt::first()->id, 'exam_session_id' => $c->exam_session_id, 'type' => 'other', 'severity' => 'medium', 'status' => 'open', 'title' => 'Check']);
        $this->assertSame(0, $svc->purgeExpired());
        $incident->update(['status' => 'closed']);
        $this->assertSame(1, $svc->purgeExpired());
        $this->assertSame(0, RecordingChunk::count());
        $this->assertSame([], Storage::disk('local')->allFiles('recordings'));
    }

    public function test_duration_is_written_only_where_it_is_safe(): void
    {
        $out = WebmDuration::write($this->webmStart(), 61500.0);
        $this->assertStringContainsString("\x44\x89\x88".pack('E', 61500.0), $out);
        $this->assertSame($out, WebmDuration::write($out, 99.0)); // already has a length
        $this->assertSame('not a webm', WebmDuration::write('not a webm', 1.0));
    }
}
