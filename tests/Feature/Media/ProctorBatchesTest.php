<?php

namespace Tests\Feature\Media;

use App\Models\Attempt;
use App\Models\ExamCandidate;
use App\Models\MediaRecording;
use App\Models\ProctorAssignment;
use App\Models\User;
use App\Services\Media\LiveKit;
use App\Services\Proctoring\ProctorBatches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/** Live proctoring in batches: one live room per batch, batches given to supervisors, recording regardless. */
class ProctorBatchesTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.livekit' => ['url' => 'wss://media.test', 'api_url' => 'https://media.test', 'key' => 'APIkey1', 'secret' => str_repeat('s', 40), 'recordings_path' => '/recordings']]);
        $this->setUpPlatform();
    }

    private function claims(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }

    private function sittingOf(int $n): array
    {
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => false, 'recording_mode' => 'stream', 'live_video' => true, 'record_video' => true]]);
        $session = $this->sitting($exam);
        $cands = collect(range(1, $n))->map(fn () => $this->candidate($session));

        return [$session, $cands];
    }

    private function proctor($session, string $role = 'proctor'): User
    {
        $u = User::factory()->create(['user_type' => 'staff']);
        $u->assignRole('proctor');
        ProctorAssignment::create(['exam_session_id' => $session->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    public function test_a_sitting_is_split_into_batches_and_late_candidates_join_one(): void
    {
        [$session, $cands] = $this->sittingOf(7);
        $this->actingAs($this->officer)->postJson("/proctor/sessions/{$session->id}/batches", ['size' => 3])->assertOk()->assertJsonCount(3, 'batches');
        $this->assertSame([1, 1, 1, 2, 2, 2, 3], ExamCandidate::orderBy('proctor_batch')->pluck('proctor_batch')->all());

        $late = $this->candidate($session);
        $this->assertSame(3, $late->fresh()->proctor_batch); // batch 3 had room
        $this->assertSame(3, $this->candidate($session)->fresh()->proctor_batch);
        $this->assertSame(4, $this->candidate($session)->fresh()->proctor_batch); // batch 3 is now full

        $this->actingAs($this->officer)->postJson("/proctor/sessions/{$session->id}/batches", ['size' => null])->assertOk();
        $this->assertSame(0, ExamCandidate::whereNotNull('proctor_batch')->count());
    }

    public function test_each_batch_has_its_own_live_room_for_candidates_and_proctors(): void
    {
        [$session, $cands] = $this->sittingOf(4);
        app(ProctorBatches::class)->resize($session, 2);
        $second = ExamCandidate::where('proctor_batch', 2)->first();
        $this->assertSame(2, ExamCandidate::where('proctor_batch', 2)->count());

        $token = $this->startAs($second)->json('token');
        $res = $this->api($token, 'POST', 'media-token')->assertOk()->assertJson(['batch' => 2]);
        $this->assertSame("vt{$session->tenant_id}-s{$session->id}-b2", $this->claims($res->json('token'))['video']['room']);
        $this->assertSame(2, $this->api($token, 'POST', 'heartbeat')->json('attempt.live_batch'));

        $p = $this->claims($this->actingAs($this->officer)->getJson("/proctor/sessions/{$session->id}/media-token?batch=1")->assertOk()->json('token'));
        $this->assertSame("vt{$session->tenant_id}-s{$session->id}-b1", $p['video']['room']);
    }

    public function test_supervisors_watch_only_the_batches_they_were_given(): void
    {
        [$session] = $this->sittingOf(6);
        app(ProctorBatches::class)->resize($session, 2);
        $ada = $this->proctor($session);
        $bayo = $this->proctor($session);
        $chief = $this->proctor($session, 'chief');

        $this->actingAs($ada)->postJson("/proctor/sessions/{$session->id}/batches/assign", ['assign' => [$ada->id => [1]]])->assertForbidden();
        $this->actingAs($chief)->postJson("/proctor/sessions/{$session->id}/batches/assign", ['assign' => [$ada->id => [1], $bayo->id => [2, 3]]])->assertOk()
            ->assertJsonPath('batches.0.supervisors.0.name', $ada->name);

        $this->actingAs($ada)->getJson("/proctor/sessions/{$session->id}/media-token?batch=2")->assertForbidden();
        $this->actingAs($ada)->getJson("/proctor/sessions/{$session->id}/media-token?batch=1")->assertOk();
        $this->actingAs($bayo)->getJson("/proctor/sessions/{$session->id}/media-token?batch=3")->assertOk();
        $this->actingAs($chief)->getJson("/proctor/sessions/{$session->id}/media-token?batch=2")->assertOk(); // the chief opens any batch

        // the list shows only the batch on screen, and a supervisor is kept to their own
        $live = $this->actingAs($ada)->getJson("/proctor/sessions/{$session->id}/live?batch=2")->assertOk();
        $this->assertSame(1, $live->json('batching.current'));
        $this->assertCount(2, $live->json('candidates'));
        $this->assertSame([1], $live->json('batching.allowed'));
        $this->assertCount(2, $this->actingAs($chief)->getJson("/proctor/sessions/{$session->id}/live?batch=3")->json('candidates'));
    }

    public function test_recording_happens_in_the_browser_whether_or_not_anyone_watches(): void
    {
        config(['platform.recording.where' => 'browser']);
        [$session, $cands] = $this->sittingOf(1);
        $token = $this->startAs($cands[0])->json('token');
        $this->api($token, 'POST', 'media-token')->assertOk()->assertJson(['enabled' => true, 'record' => ['camera' => true, 'always' => true]]);

        Http::fake();
        $a = Attempt::first();
        $body = json_encode(['event' => 'track_published', 'room' => ['name' => "vt{$a->tenant_id}-s{$session->id}"], 'participant' => ['identity' => "c-{$a->id}"], 'track' => ['source' => 'CAMERA']]);
        $jwt = LiveKit::sign(['iss' => 'APIkey1', 'exp' => time() + 60, 'sha256' => base64_encode(hash('sha256', $body, true))], str_repeat('s', 40));
        $this->call('POST', '/api/livekit/webhook', [], [], [], ['HTTP_AUTHORIZATION' => $jwt, 'CONTENT_TYPE' => 'application/webhook+json'], $body)->assertOk();
        $this->assertSame(0, MediaRecording::count()); // no recording load on the media server
        Http::assertNothingSent();
    }

    public function test_ai_alerts_never_count_as_strikes(): void
    {
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => false, 'violation_threshold' => 2, 'violation_action' => 'lock']]);
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        $this->api($token, 'POST', 'heartbeat', ['events' => [
            ['type' => 'multiple_faces', 'data' => ['confirmed' => '4/5']], ['type' => 'face_absent'], ['type' => 'camera_obstructed'], ['type' => 'gaze_away'], ['type' => 'noise_detected'],
        ]])->assertOk();
        $a = Attempt::first();
        $this->assertSame(0, $a->violation_count);
        $this->assertSame('in_progress', $a->status);
        $this->assertDatabaseHas('incidents', ['type' => 'ai_alert', 'needs_human_review' => true]); // a person reviews it
    }
}
