<?php

namespace Tests\Feature\Exams;

use App\Models\Exam;
use App\Models\ExamPanelAccount;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Examiners made for one panel exam: first name, last name, username, password.
 * Only that exam; not after it is over; one computer at a time; the exams office can reset the session.
 */
class ExamExaminerAccountsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private Exam $viva;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        $this->viva = Exam::create(['title' => 'MSc viva', 'type' => 'viva', 'status' => 'scheduled', 'duration_minutes' => 60, 'max_attempts' => 1, 'total_mark' => 100, 'created_by' => $this->officer->id]);
        $this->viva->criteria()->create(['text' => 'Defence', 'max_score' => 100, 'position' => 0]);
        $this->candidate($this->sitting($this->viva, ['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3)]));
    }

    private function create(array $over = []): ExamPanelAccount
    {
        $this->actingAs($this->officer)->post("/exams/{$this->viva->id}/examiner-accounts", $over + ['first_name' => 'Ngozi', 'last_name' => 'Eze', 'username' => 'ngozi.eze', 'password' => 'Viva-pass-2026'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        auth()->guard('web')->logout();
        $this->flushSession();

        return ExamPanelAccount::latest('id')->firstOrFail();
    }

    private function signIn(string $username = 'ngozi.eze', string $password = 'Viva-pass-2026')
    {
        return $this->post('/login', ['email' => $username, 'password' => $password]);
    }

    /** A second computer: a fresh browser with no cookies. */
    private function newComputer(): void
    {
        auth()->forgetGuards();
        $this->flushSession();
    }

    public function test_the_exams_office_creates_an_examiner_with_names_username_and_password(): void
    {
        $a = $this->create();
        $this->assertSame(['Ngozi', 'Eze', 'ngozi.eze'], [$a->first_name, $a->last_name, $a->username]);
        $this->assertSame('Ngozi Eze', $a->user->name);
        $this->assertSame(ExamPanelAccount::USER_TYPE, $a->user->user_type);
        $this->assertTrue($this->viva->examiners()->where('users.id', $a->user_id)->exists());

        // the same username twice, spaces, or a short password are refused; only panel exams get these accounts
        $this->actingAs($this->officer)->post("/exams/{$this->viva->id}/examiner-accounts", ['first_name' => 'A', 'last_name' => 'B', 'username' => 'NGOZI.EZE', 'password' => 'Another-pass-1'])->assertSessionHasErrors('username');
        $this->actingAs($this->officer)->post("/exams/{$this->viva->id}/examiner-accounts", ['first_name' => 'A', 'last_name' => 'B', 'username' => 'has space', 'password' => 'Another-pass-1'])->assertSessionHasErrors('username');
        $this->actingAs($this->officer)->post("/exams/{$this->viva->id}/examiner-accounts", ['first_name' => 'A', 'last_name' => 'B', 'username' => 'shorty', 'password' => 'short'])->assertSessionHasErrors('password');
        $cbt = $this->cbtExam();
        $this->actingAs($this->officer)->post("/exams/{$cbt->id}/examiner-accounts", ['first_name' => 'A', 'last_name' => 'B', 'username' => 'cbtx', 'password' => 'Another-pass-1'])->assertStatus(422);
        // staff without exams.manage cannot make them
        $lecturer = \App\Models\User::factory()->create(['user_type' => 'staff']);
        $lecturer->assignRole('lecturer');
        $this->actingAs($lecturer)->post("/exams/{$this->viva->id}/examiner-accounts", ['first_name' => 'A', 'last_name' => 'B', 'username' => 'zz1', 'password' => 'Another-pass-1'])->assertForbidden();
    }

    public function test_the_examiner_signs_in_with_the_username_and_sees_only_this_exam(): void
    {
        $this->create();
        $this->signIn('Ngozi.Eze')->assertRedirect();
        $this->assertAuthenticated();

        $this->get("/marking/{$this->viva->id}/panel")->assertOk()->assertInertia(fn ($p) => $p->component('marking/panel')->where('auth.examinerExam.id', $this->viva->id));
        $c = \App\Models\ExamCandidate::first();
        $crit = $this->viva->criteria()->first();
        $this->post("/marking/{$this->viva->id}/panel", ['user_id' => $c->user_id, 'scores' => [$crit->id => ['score' => 64]]])->assertSessionHas('success');
        $this->assertDatabaseHas('criterion_marks', ['exam_id' => $this->viva->id, 'user_id' => $c->user_id, 'score' => 64]);

        // everything else in the system sends them back to their scoring sheet, or is refused
        $sheet = "/marking/{$this->viva->id}/panel";
        foreach (['/dashboard', '/exams', '/exam-board', '/marking', '/question-bank', '/learn/courses', '/admin/people', '/settings/profile', '/my/exams'] as $page) {
            $this->get($page)->assertRedirect($sheet);
        }
        $other = Exam::create(['title' => 'Other viva', 'type' => 'viva', 'status' => 'scheduled', 'total_mark' => 100, 'created_by' => $this->officer->id]);
        $this->get("/marking/{$other->id}/panel")->assertRedirect($sheet);
        $this->post("/marking/{$other->id}/panel", ['user_id' => $c->user_id, 'scores' => []])->assertForbidden();
        $this->post("/exams/{$this->viva->id}/examiner-accounts", ['first_name' => 'x', 'last_name' => 'y', 'username' => 'sneaky', 'password' => 'Another-pass-1'])->assertForbidden();
    }

    public function test_it_joins_the_meeting_room_held_for_this_exam_only(): void
    {
        $this->create();
        $room = Meeting::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'bbb_meeting_id' => 'm1', 'type' => 'viva', 'title' => 'Viva room', 'starts_at' => now(), 'duration_minutes' => 60,
            'access' => 'invited', 'settings' => ['exam_id' => $this->viva->id], 'created_by' => $this->officer->id]);
        $class = Meeting::create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'bbb_meeting_id' => 'm2', 'type' => 'class', 'title' => 'Some class', 'starts_at' => now(), 'duration_minutes' => 60,
            'access' => 'institution', 'settings' => [], 'created_by' => $this->officer->id]);
        $this->signIn();
        $this->get("/meetings/{$room->uuid}")->assertOk();
        $this->get("/meetings/{$class->uuid}")->assertRedirect("/marking/{$this->viva->id}/panel");
    }

    public function test_one_computer_at_a_time_until_the_exams_office_resets_the_session(): void
    {
        $a = $this->create();
        $this->signIn()->assertRedirect();
        $first = $this->app['session']->all();
        $this->assertTrue($a->fresh()->isSignedIn());

        // a second computer is refused while the first is signed in
        $this->newComputer();
        $this->signIn()->assertSessionHasErrors(['email' => 'This examiner account is already signed in on another computer. Ask the exams office to reset your session.']);
        $this->assertGuest();
        $this->assertDatabaseHas('security_events', ['user_id' => $a->user_id, 'type' => 'second_sign_in_refused']);

        // the exams office resets the session: the first computer is signed out, the second may sign in
        $this->actingAs($this->officer)->post("/exams/{$this->viva->id}/examiner-accounts/{$a->id}/reset-session")->assertSessionHas('success');
        $this->assertDatabaseHas('audit_logs', ['action' => 'exam.examiner_session_reset']);
        $this->newComputer();
        $this->withSession($first)->actingAs($a->user)->get("/marking/{$this->viva->id}/panel")->assertRedirect('/login');
        $this->assertGuest();
        $this->newComputer();
        $this->signIn()->assertRedirect();
        $this->assertAuthenticated();

        // signing out frees the account straight away
        $this->post('/logout');
        $this->assertNull($a->fresh()->session_token);
        $this->newComputer();
        $this->signIn()->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_once_the_exam_is_over_the_account_cannot_sign_in(): void
    {
        $a = $this->create();
        $this->signIn()->assertRedirect();
        $session = $this->app['session']->all();

        // the last sitting ends: the open session ends on its next request and new sign-ins are refused
        $this->viva->sessions()->update(['starts_at' => now()->subHours(3), 'ends_at' => now()->subMinute()]);
        $this->get("/marking/{$this->viva->id}/panel")->assertRedirect('/login');
        $this->assertGuest();
        $this->newComputer();
        $this->signIn()->assertSessionHasErrors('email');
        $this->assertStringContainsString('is over', session('errors')->first('email'));

        // closing the exam does the same even if a sitting is still running
        $this->viva->sessions()->update(['ends_at' => now()->addHour()]);
        $this->newComputer();
        $this->signIn()->assertRedirect();
        $this->viva->update(['status' => 'closed']);
        $this->get("/marking/{$this->viva->id}/panel")->assertRedirect('/login');
        $this->assertTrue($a->fresh()->isOver());
    }

    public function test_removed_accounts_and_new_passwords(): void
    {
        $a = $this->create();
        $this->actingAs($this->officer)->put("/exams/{$this->viva->id}/examiner-accounts/{$a->id}/password", ['password' => 'Brand-new-pass-9'])->assertSessionHas('success');
        $this->newComputer();
        $this->signIn()->assertSessionHasErrors('email');
        $this->signIn('ngozi.eze', 'Brand-new-pass-9')->assertRedirect();
        $this->assertAuthenticated();

        $this->newComputer();
        $this->actingAs($this->officer)->delete("/exams/{$this->viva->id}/examiner-accounts/{$a->id}")->assertSessionHas('success');
        $this->newComputer();
        $this->signIn('ngozi.eze', 'Brand-new-pass-9')->assertSessionHasErrors('email');
        $this->assertGuest();

        // usernames belong to one institution: the same username elsewhere does not sign in here
        $this->assertSame(0, ExamPanelAccount::where('username', 'nobody')->count());
        $this->signIn('nobody', 'Brand-new-pass-9')->assertSessionHasErrors('email');
    }

    public function test_the_exam_page_lists_them_for_the_exams_office(): void
    {
        $this->create();
        $this->actingAs($this->officer)->get("/exams/{$this->viva->id}")->assertOk()->assertInertia(fn ($p) => $p->has('examinerAccounts', 1)
            ->where('examinerAccounts.0.username', 'ngozi.eze')->where('examinerAccounts.0.signed_in', false)->where('examinerAccounts.0.over', false)
            ->has('examiners', 0)); // they are listed with the exam-only accounts, not among staff examiners
    }
}
