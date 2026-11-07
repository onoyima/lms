<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\Device;
use App\Models\EvidenceFile;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChannelsAndEvidenceTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_an_exam_restricted_to_secure_clients_refuses_a_plain_browser(): void
    {
        $exam = $this->cbtExam(['channels' => ['desktop', 'seb']]);
        $c = $this->candidate($this->sitting($exam));

        $this->startAs($c)->assertStatus(403)->assertJsonPath('reason', 'wrong_channel');
    }

    public function test_seb_browser_exam_key_is_verified(): void
    {
        $bek = str_repeat('ab', 32);
        $exam = $this->cbtExam(['channels' => ['seb'], 'settings' => ['require_check_in' => false, 'seb' => ['browser_exam_keys' => [$bek]]]]);
        $c = $this->candidate($this->sitting($exam));
        $url = url("/my/exams/{$c->id}/start");

        $this->startAs($c, [], ['X-SafeExamBrowser-RequestHash' => hash('sha256', $url.'wrong')])->assertJsonPath('reason', 'wrong_channel');
        $this->startAs($c, [], ['X-SafeExamBrowser-RequestHash' => hash('sha256', $url.$bek)])->assertOk();
        $this->assertSame('seb', Attempt::first()->channel);
    }

    public function test_desktop_app_requests_must_be_signed_by_an_approved_computer(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $deviceId = 'desk-'.str_repeat('7', 20);
        $this->postJson('/api/devices/enrol', ['device_id' => $deviceId, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp)), 'label' => 'Lab 1 PC 07'])
            ->assertCreated()->assertJsonPath('status', 'pending');

        $exam = $this->cbtExam(['channels' => ['desktop']]);
        $c = $this->candidate($this->sitting($exam));
        $sign = function (string $path, string $body = '') use ($kp, $deviceId) {
            $ts = time();
            $msg = "POST\n/".ltrim($path, '/')."\n$ts\n".hash('sha256', $body);

            return ['X-Platform-Device' => $deviceId, 'X-Platform-Timestamp' => (string) $ts, 'X-Platform-Signature' => base64_encode(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($kp)))];
        };
        $path = "my/exams/{$c->id}/start";

        $this->startAs($c, [], $sign($path, '[]'))->assertJsonPath('reason', 'wrong_channel'); // still pending
        Device::first()->update(['status' => 'approved']);
        $this->startAs($c, [], ['X-Platform-Signature' => 'bad'] + $sign($path, '[]'))->assertJsonPath('reason', 'wrong_channel');
        $token = $this->startAs($c, [], $sign($path, '[]'))->assertOk()->json('token');
        $this->assertSame('desktop', Attempt::first()->channel);

        // the token alone is useless elsewhere: every exam call must be signed by the same computer
        $this->api($token, 'GET', 'paper')->assertStatus(403)->assertJsonPath('reason', 'wrong_device');
        $signGet = function (string $p) use ($kp, $deviceId) {
            $ts = time();
            $msg = "GET\n/$p\n$ts\n".hash('sha256', '');

            return ['X-Platform-Device' => $deviceId, 'X-Platform-Timestamp' => (string) $ts, 'X-Platform-Signature' => base64_encode(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($kp)))];
        };
        $this->withHeaders($signGet('api/exam/attempt/paper') + ['Authorization' => "Bearer $token", 'Accept' => 'application/json'])->get('/api/exam/attempt/paper')->assertOk();
        $other = sodium_crypto_sign_keypair();
        $this->postJson('/api/devices/enrol', ['device_id' => 'desk-'.str_repeat('8', 20), 'public_key' => base64_encode(sodium_crypto_sign_publickey($other))]);
        Device::query()->update(['status' => 'approved']);
        $ts = time();
        $forged = ['X-Platform-Device' => 'desk-'.str_repeat('8', 20), 'X-Platform-Timestamp' => (string) $ts,
            'X-Platform-Signature' => base64_encode(sodium_crypto_sign_detached("GET\n/api/exam/attempt/paper\n$ts\n".hash('sha256', ''), sodium_crypto_sign_secretkey($other)))];
        $this->withHeaders($forged + ['Authorization' => "Bearer $token", 'Accept' => 'application/json'])->get('/api/exam/attempt/paper')->assertStatus(403); // another approved PC
    }

    public function test_seb_settings_file_is_generated(): void
    {
        $exam = $this->cbtExam();
        $c = $this->candidate($this->sitting($exam));

        $res = $this->actingAs($c->user)->get("/my/exams/{$c->id}/seb")->assertOk();
        $this->assertStringContainsString('<key>sendBrowserExamKey</key><true/>', $res->getContent());
        $this->assertStringContainsString('<key>allowVirtualMachine</key><false/>', $res->getContent());
    }

    public function test_evidence_is_encrypted_hashed_and_every_view_is_logged(): void
    {
        Storage::fake('local');
        $exam = $this->cbtExam();
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        $jpeg = UploadedFile::fake()->image('snap.jpg', 64, 48);

        $id = $this->withHeaders(['Authorization' => "Bearer $token"])->post('/api/exam/attempt/evidence', ['kind' => 'snapshot', 'file' => $jpeg])->assertCreated()->json('id');
        $file = EvidenceFile::find($id);
        $raw = Storage::disk('local')->get($file->path);
        $this->assertStringNotContainsString('JFIF', base64_decode($raw) ?: $raw); // not readable on disk

        $this->actingAs($this->officer)->get("/proctor/evidence/{$id}")->assertOk();
        $this->assertDatabaseHas('evidence_access_logs', ['evidence_file_id' => $id, 'action' => 'view', 'user_id' => $this->officer->id]);

        // tampering is detected
        $file->forceFill(['sha256' => str_repeat('0', 64)])->save();
        $this->actingAs($this->officer)->get("/proctor/evidence/{$id}")->assertStatus(409);
    }

    public function test_students_cannot_see_the_proctor_console_or_evidence(): void
    {
        $exam = $this->cbtExam();
        $s = $this->sitting($exam);
        $c = $this->candidate($s);
        $c->user->assignRole('student');

        $this->actingAs($c->user)->get("/proctor/sessions/{$s->id}/live")->assertForbidden();
        $this->actingAs($c->user)->get('/exams')->assertForbidden();
    }

    public function test_attempt_tokens_do_not_cross_institutions(): void
    {
        $exam = $this->cbtExam();
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        Tenant::create(['slug' => 'other', 'name' => 'Other', 'domains' => ['exams.other.test']]);

        $this->app['auth']->forgetGuards(); // a plain API call, no web session
        $status = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('http://exams.other.test/api/exam/attempt/paper')->status();
        $this->assertContains($status, [401, 403]);
    }

    public function test_live_console_summarises_candidates(): void
    {
        $exam = $this->cbtExam();
        $s = $this->sitting($exam);
        $c1 = $this->candidate($s);
        $this->candidate($s);
        $t = $this->startAs($c1)->json('token');
        $this->api($t, 'POST', 'heartbeat', ['status' => 'active', 'events' => [['type' => 'tab_hidden']]]);

        $live = $this->actingAs($this->officer)->getJson("/proctor/sessions/{$s->id}/live")->assertOk()->json();
        $this->assertSame(2, $live['summary']['expected']);
        $this->assertSame(1, $live['summary']['writing']);
        $this->assertSame(1, $live['candidates'][0]['attempt']['violations']); // highest risk first
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('X-Powered-By');
        $this->assertStringContainsString("frame-ancestors 'none'", $this->get('/')->headers->get('Content-Security-Policy'));
    }
}
