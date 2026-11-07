<?php

namespace Tests\Feature\Media;

use App\Models\Attempt;
use App\Models\Incident;
use App\Models\MediaRecording;
use App\Models\ProctorAssignment;
use App\Models\User;
use App\Services\Media\LiveKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class LiveMediaTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.livekit' => ['url' => 'wss://media.test', 'api_url' => 'https://media.test', 'key' => 'APIkey1', 'secret' => str_repeat('s', 40), 'recordings_path' => '/recordings']]);
    }

    private function claims(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }

    private function liveExam(array $p = [])
    {
        return $this->cbtExam(['proctoring' => array_merge(['require_consent' => false, 'live_video' => true, 'record_video' => true, 'microphone' => true, 'second_camera' => true], $p)]);
    }

    private function hook(array $event)
    {
        $body = json_encode($event);
        $token = LiveKit::sign(['iss' => 'APIkey1', 'exp' => time() + 60, 'sha256' => base64_encode(hash('sha256', $body, true))], str_repeat('s', 40));

        return $this->call('POST', '/api/livekit/webhook', [], [], [], ['HTTP_AUTHORIZATION' => $token, 'CONTENT_TYPE' => 'application/webhook+json'], $body);
    }

    public function test_candidates_publish_only_and_proctors_watch_hidden(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->liveExam()));
        $token = $this->startAs($c)->json('token');

        $res = $this->api($token, 'POST', 'media-token')->assertOk()->assertJson(['enabled' => true, 'url' => 'wss://media.test']);
        $g = $this->claims($res->json('token'));
        $this->assertSame('c-'.Attempt::first()->id, $g['sub']);
        $this->assertTrue($g['video']['canPublish']);
        $this->assertFalse($g['video']['canSubscribe']); // a candidate can never see others
        $this->assertSame(['camera', 'microphone'], $g['video']['canPublishSources']);
        $this->assertLessThanOrEqual(time() + 3 * 3600, $g['exp']);

        $stranger = User::factory()->create();
        $stranger->assignRole('proctor');
        $this->actingAs($stranger)->getJson("/proctor/sessions/{$c->exam_session_id}/media-token")->assertForbidden();
        ProctorAssignment::create(['exam_session_id' => $c->exam_session_id, 'user_id' => $stranger->id]);
        $p = $this->claims($this->actingAs($stranger)->getJson("/proctor/sessions/{$c->exam_session_id}/media-token")->assertOk()->json('token'));
        $this->assertTrue($p['video']['hidden']);
        $this->assertFalse($p['video']['canPublish']);
        $this->assertTrue($p['video']['canSubscribe']);
    }

    public function test_without_a_media_server_everything_falls_back(): void
    {
        config(['platform.livekit.url' => '']);
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->liveExam()));
        $token = $this->startAs($c)->json('token');
        $this->api($token, 'POST', 'media-token')->assertOk()->assertJson(['enabled' => false]);
    }

    public function test_phone_link_works_once_and_only_for_its_exam(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->liveExam()));
        $token = $this->startAs($c)->json('token');
        $link = $this->api($token, 'POST', 'phone-link')->assertOk();
        $this->assertStringContainsString('<svg', $link->json('qr'));
        $code = basename($link->json('url'));

        auth()->guard('web')->logout();
        $this->get("/phone/$code")->assertOk()->assertInertia(fn ($p) => $p->where('valid', true));
        $phone = $this->postJson("/phone/$code/token")->assertOk();
        $this->assertSame(['camera'], $this->claims($phone->json('token'))['video']['canPublishSources']);
        $this->assertStringEndsWith('-phone', $this->claims($phone->json('token'))['sub']);
        $this->postJson("/phone/$code/token")->assertStatus(410); // single use
        $this->postJson('/phone/'.str_repeat('a', 40).'/token')->assertStatus(410);

        // exams without a second camera cannot make links
        $c2 = $this->candidate($this->sitting($this->liveExam(['second_camera' => false])));
        $this->api($this->startAs($c2)->json('token'), 'POST', 'phone-link')->assertStatus(409);
    }

    public function test_webhooks_must_be_signed_and_drive_recordings_and_alerts(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->liveExam()));
        $this->startAs($c)->assertOk();
        $a = Attempt::first();
        $room = 'vt'.$a->tenant_id.'-s'.$a->exam_session_id;
        Http::fake(['media.test/twirp/livekit.Egress/StartParticipantEgress' => Http::response(['egress_id' => 'EG_1'])]);

        $this->call('POST', '/api/livekit/webhook', [], [], [], ['HTTP_AUTHORIZATION' => 'forged', 'CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(401);

        $this->hook(['event' => 'track_published', 'room' => ['name' => $room], 'participant' => ['identity' => "c-{$a->id}"], 'track' => ['source' => 'CAMERA']])->assertOk();
        $this->hook(['event' => 'track_published', 'room' => ['name' => $room], 'participant' => ['identity' => "c-{$a->id}"], 'track' => ['source' => 'CAMERA']])->assertOk();
        $this->assertSame(1, MediaRecording::count()); // never two recordings of the same camera
        Http::assertSent(fn ($r) => str_contains($r->url(), 'StartParticipantEgress') && $r['identity'] === "c-{$a->id}" && str_starts_with($r->header('Authorization')[0], 'Bearer '));

        $this->hook(['event' => 'egress_ended', 'egressInfo' => ['egressId' => 'EG_1', 'roomName' => $room, 'status' => 'EGRESS_COMPLETE',
            'fileResults' => [['location' => '/recordings/t1/a1/camera.mp4', 'size' => 1234, 'duration' => 60e9]]]])->assertOk();
        $rec = MediaRecording::first();
        $this->assertSame('complete', $rec->status);
        $this->assertSame(60, $rec->duration_seconds);

        $this->hook(['event' => 'participant_left', 'room' => ['name' => $room], 'participant' => ['identity' => "c-{$a->id}-phone"]])->assertOk();
        $this->assertDatabaseHas('proctoring_events', ['attempt_id' => $a->id, 'type' => 'second_camera_lost']);
        $this->assertTrue(Incident::where('title', 'like', 'second_camera_lost%')->exists());
    }

    public function test_recordings_play_only_from_the_recordings_folder(): void
    {
        $this->setUpPlatform();
        $dir = sys_get_temp_dir().'/vt-rec-'.uniqid();
        mkdir("$dir/t1", 0777, true);
        file_put_contents("$dir/t1/cam.mp4", 'MP4DATA');
        file_put_contents(sys_get_temp_dir().'/vt-secret.txt', 'SECRET');
        config(['platform.livekit.recordings_local' => $dir]);
        $c = $this->candidate($this->sitting($this->liveExam()));
        $this->startAs($c);
        $a = Attempt::first();
        $ok = MediaRecording::create(['attempt_id' => $a->id, 'kind' => 'camera', 'egress_id' => 'E1', 'status' => 'complete', 'location' => '/recordings/t1/cam.mp4']);
        $evil = MediaRecording::create(['attempt_id' => $a->id, 'kind' => 'camera', 'egress_id' => 'E2', 'status' => 'complete', 'location' => '/recordings/../vt-secret.txt']);

        $this->actingAs($this->officer)->get("/proctor/recordings/{$ok->id}")->assertOk();
        $this->actingAs($this->officer)->get("/proctor/recordings/{$evil->id}")->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'evidence.recording_viewed']);
        $this->actingAs($c->user)->get("/proctor/recordings/{$ok->id}")->assertForbidden();
    }
}
