<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\LoginCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/** Sign in with a one-time code by email or text message; text messages for exams and results. */
class LoginCodeAndSmsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_a_code_by_email_signs_in_once(): void
    {
        Notification::fake();
        $u = User::factory()->create(['email' => 'ada@uni.test', 'identifier' => 'VUG/1', 'user_type' => 'student']);
        $this->get('/login')->assertOk();
        $this->get('/login/code')->assertOk()->assertInertia(fn ($p) => $p->component('auth/login-code')->where('sms', false));

        // the same answer for an account that does not exist
        $this->post('/login/code', ['login' => 'nobody@uni.test'])->assertRedirect('/login/code');
        Notification::assertNothingSent();

        $this->post('/login/code', ['login' => 'VUG/1'])->assertRedirect('/login/code');
        $code = null;
        Notification::assertSentTo($u, LoginCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return strlen($n->code) === 6;
        });
        $this->assertStringNotContainsString($code, json_encode(DB::table('login_codes')->get()));

        $this->post('/login/code/verify', ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/login/code/verify', ['code' => $code])->assertRedirect();
        $this->assertAuthenticatedAs($u);

        // used: it does not work again
        auth()->logout();
        $this->post('/login/code/verify', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_five_wrong_tries_end_a_code(): void
    {
        Notification::fake();
        $u = User::factory()->create(['email' => 'b@uni.test']);
        $this->post('/login/code', ['login' => 'b@uni.test']);
        $code = null;
        Notification::assertSentTo($u, LoginCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        foreach (range(1, 5) as $_) {
            $this->post('/login/code/verify', ['code' => $code === '123456' ? '654321' : '123456']);
        }
        $this->post('/login/code/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_sms_codes_and_exam_texts_through_the_institutions_provider(): void
    {
        Http::fake(['api.ng.termii.com/*' => Http::response(['message_id' => 'T1', 'message' => 'Successfully Sent'])]);
        $this->actingAs($this->officer)->put('/admin/sms', ['provider' => 'termii', 'sender' => 'VUNA', 'key' => 'termii-key'])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('termii-key', json_encode($this->tenant->fresh()->settings));
        $this->postJson('/admin/sms/test', ['provider' => 'termii', 'sender' => 'VUNA', 'to' => '0803 123 4567'])->assertJsonPath('ok', true);
        Http::assertSent(fn ($r) => $r['to'] === '2348031234567' && $r['api_key'] === 'termii-key' && $r['from'] === 'VUNA');

        $student = User::factory()->create(['user_type' => 'student', 'email' => 's@uni.test', 'identifier' => 'VUG/9']);
        $this->actingAs($student)->patch('/settings/profile', ['name' => $student->name, 'email' => $student->email, 'phone' => '08031112222'])->assertSessionHasNoErrors();
        $this->assertSame('08031112222', $student->fresh()->phone);
        $this->get('/settings/notifications')->assertInertia(fn ($p) => $p->where('sms.phone', '08031112222')->where('categories.2.sms', true));
        auth()->logout();

        // a sign-in code by text message
        $this->post('/login/code', ['login' => 'VUG/9', 'via' => 'sms'])->assertSessionHas('login_code.sent', fn ($s) => str_contains($s['to'], 'ending 222'));
        Http::assertSent(fn ($r) => $r['to'] === '2348031112222' && str_contains($r['sms'], 'Your sign-in code is'));

        // an exam time by text message (texts for exams are on unless turned off)
        $exam = $this->cbtExam();
        $session = $this->sitting($exam);
        $student->fresh()->notify(new \App\Notifications\ExamScheduled($session));
        Http::assertSent(fn ($r) => $r['to'] === '2348031112222' && str_contains($r['sms'], 'Exam: '.$exam->title));
        $this->assertSame(0, DB::table('sms_messages')->where('to', 'like', '%2222')->count()); // numbers are masked in the log
        $this->assertGreaterThanOrEqual(3, DB::table('sms_messages')->where('status', 'sent')->count());
    }
}
