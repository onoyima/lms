<?php

namespace Tests\Feature\Exams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Students with an exam open go straight to it after signing in; an emergency exam is then the only
 * thing they see, and starts with one press with nothing to check first.
 */
class EmergencyExamTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    private function signIn($user)
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    }

    public function test_a_student_with_an_exam_open_lands_on_my_exams(): void
    {
        $c = $this->candidate($this->sitting($this->cbtExam()));
        $this->signIn($c->user)->assertRedirect('/my/exams');
    }

    public function test_a_student_without_an_open_exam_lands_on_the_dashboard(): void
    {
        $c = $this->candidate($this->sitting($this->cbtExam(), ['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2)]));
        $this->signIn($c->user)->assertRedirect('/dashboard');
    }

    public function test_a_finished_exam_no_longer_sends_the_student_to_it(): void
    {
        $c = $this->candidate($this->sitting($this->cbtExam()));
        $token = $this->startAs($c)->assertOk()->json('token');
        $this->api($token, 'POST', 'submit')->assertSuccessful();
        auth()->logout();

        $this->signIn($c->user)->assertRedirect('/dashboard');
    }

    public function test_an_emergency_exam_is_all_the_student_sees_and_starts_without_any_checks(): void
    {
        $exam = $this->cbtExam([
            'settings' => ['shuffle_questions' => false, 'shuffle_options' => false, 'require_check_in' => true, 'emergency' => true],
            'proctoring' => ['require_consent' => true, 'webcam' => true, 'require_fullscreen' => true, 'identity_check' => 'photo_match', 'pre_checks' => ['camera', 'network', 'keyboard'], 'violation_threshold' => 3, 'violation_action' => 'lock'],
        ]);
        // a hall sitting with check-in required: an emergency exam skips the check-in too
        $c = $this->candidate($this->sitting($exam, ['mode' => 'hall']));

        $this->signIn($c->user)->assertRedirect('/my/exams/now');
        $this->get('/dashboard')->assertRedirect('/my/exams/now');
        $this->get('/my/exams')->assertRedirect('/my/exams/now');
        $this->get('/my/exams/now')->assertOk()->assertInertia(fn ($p) => $p->component('candidate/exam-now')->has('exams', 1)->where('exams.0.candidate_id', $c->id));
        $this->get("/my/exams/{$c->id}")->assertInertia(fn ($p) => $p->where('exam.emergency', true)->where('exam.proctoring.pre_checks', [])
            ->where('exam.proctoring.require_consent', false)->where('exam.proctoring.webcam', false)->where('exam.proctoring.require_fullscreen', false));

        // no checks, no consent, no check-in: one press
        $token = $this->startAs($c)->assertOk()->json('token');
        $this->api($token, 'GET', 'paper')->assertOk();
    }

    public function test_after_an_emergency_exam_the_student_has_the_usual_pages_again(): void
    {
        $exam = $this->cbtExam(['settings' => ['shuffle_questions' => false, 'shuffle_options' => false, 'require_check_in' => false, 'emergency' => true]]);
        $c = $this->candidate($this->sitting($exam));
        $token = $this->startAs($c)->assertOk()->json('token');
        $this->api($token, 'POST', 'submit')->assertSuccessful();

        $this->actingAs($c->user)->get('/dashboard')->assertOk();
        $this->get('/my/exams/now')->assertRedirect('/my/exams');
    }

    public function test_the_switch_is_saved_on_the_exam(): void
    {
        $exam = $this->cbtExam(['status' => 'draft']);
        $this->actingAs($this->officer)->put("/exams/{$exam->id}", ['settings' => ['emergency' => true]])->assertSessionHasNoErrors();
        $this->assertTrue($exam->fresh()->settings['emergency']);
    }
}
