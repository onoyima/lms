<?php

namespace Tests\Feature\Exams;

use App\Models\ComputerCheck;
use App\Models\ComputerReport;
use App\Models\ExamComputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Computer checks before an exam, and computer problems reported during exams, with the computer's IP. */
class ComputerChecksTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    private function results(array $override = []): array
    {
        $all = ['browser' => true, 'storage' => true, 'server' => true, 'speed' => true, 'clock' => true, 'screen' => true, 'camera' => true];

        return collect(array_replace($all, $override))->map(fn ($ok, $k) => ['key' => $k, 'ok' => $ok, 'detail' => $ok ? 'fine' : 'No camera found'])->values()->all();
    }

    public function test_a_check_for_a_sitting_tests_what_its_exam_needs_and_lab_computers_report_with_their_ip(): void
    {
        $session = $this->sitting($this->cbtExam(['proctoring' => ['webcam' => true]]));
        $this->actingAs($this->officer)->post('/computer-checks', ['name' => 'Lab 2', 'exam_session_id' => $session->id])->assertRedirect();
        $check = ComputerCheck::sole();
        $this->assertContains('camera', $check->tests());

        auth()->logout();
        $this->get("/check/{$check->code}")->assertOk()->assertInertia(fn ($p) => $p->component('check/run')->where('check.name', 'Lab 2'));
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.2.14'])->postJson("/check/{$check->code}/report", ['label' => 'B14', 'results' => $this->results(['camera' => false])])
            ->assertOk()->assertJson(['status' => 'fail', 'ip' => '10.10.2.14']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.2.15'])->postJson("/check/{$check->code}/report", ['results' => $this->results()])->assertJson(['status' => 'pass']);
        // testing again updates the same computer's row
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.2.14'])->postJson("/check/{$check->code}/report", ['label' => 'B14', 'results' => $this->results()])->assertJson(['status' => 'pass']);
        $this->assertSame(2, ComputerReport::count());

        $rows = $this->actingAs($this->officer)->getJson("/computer-checks/{$check->id}/rows")->assertOk()->json('rows');
        $this->assertSame(['10.10.2.14', '10.10.2.15'], collect($rows)->pluck('ip')->sort()->values()->all());

        $this->post("/computer-checks/{$check->id}/approve")->assertRedirect();
        $this->assertSame(2, ExamComputer::where('status', 'approved')->count());

        $this->post("/computer-checks/{$check->id}/close");
        $this->postJson("/check/{$check->code}/report", ['results' => $this->results()])->assertStatus(410);
    }

    public function test_computer_problems_during_exams_are_listed_with_the_ip(): void
    {
        $session = $this->sitting($this->cbtExam());
        $token = $this->withServerVariables(['REMOTE_ADDR' => '10.10.3.7'])->startAs($this->candidate($session))->json('token');
        $this->withServerVariables(['REMOTE_ADDR' => '10.10.3.7'])->api($token, 'POST', 'problem', ['kind' => 'script_error', 'message' => 'TypeError: x is undefined'])->assertOk();
        $this->api($token, 'POST', 'heartbeat', ['events' => [['type' => 'network_offline']]]);
        $this->api($token, 'POST', 'problem', ['kind' => 'nonsense'])->assertStatus(422);

        $this->actingAs($this->officer)->get('/computer-checks')->assertOk()->assertInertia(fn ($p) => $p->component('exams/computer-checks')
            ->where('problems.0.ip', '10.10.3.7')
            ->where('problems.0.problems', fn ($list) => collect($list)->pluck('label')->sort()->values()->all() === ['Exam screen error', 'Lost the network']));
    }

    public function test_only_the_exams_office_manages_checks(): void
    {
        $student = \App\Models\User::factory()->create(['user_type' => 'student']);
        $this->actingAs($student)->get('/computer-checks')->assertForbidden();
        $this->get('/check/zzzzzzzz')->assertNotFound();
    }
}
