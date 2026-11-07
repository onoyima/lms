<?php

namespace Tests\Feature\Exams;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\Incident;
use App\Models\ProctorAssignment;
use App\Models\Result;
use App\Models\Semester;
use App\Models\User;
use App\Models\Venue;
use App\Services\Proctoring\ProctorWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private Semester $sem;

    private function setPolicy(array $p): void
    {
        $this->tenant->update(['settings' => array_replace_recursive((array) $this->tenant->settings, ['policy' => $p])]);
        tenant()?->refresh();
    }

    /** A course exam with registered students. @return array{0: Exam, 1: CourseOffering, 2: \Illuminate\Support\Collection} */
    private function courseExam(string $code, array $students): array
    {
        $this->sem ??= Semester::create(['academic_session_id' => AcademicSession::create(['name' => '2026/2027'])->id, 'name' => 'First', 'is_current' => true]);
        $course = Course::create(['code' => $code, 'title' => $code, 'credit_units' => 3, 'level' => 100]);
        $o = CourseOffering::create(['course_id' => $course->id, 'semester_id' => $this->sem->id, 'status' => 'open']);
        foreach ($students as $u) {
            Enrolment::create(['course_offering_id' => $o->id, 'user_id' => $u->id, 'status' => 'active']);
        }
        $exam = $this->cbtExam(['title' => "$code Exam", 'course_id' => $course->id, 'semester_id' => $this->sem->id, 'status' => 'approved', 'duration_minutes' => 60, 'pass_mark' => 40]);

        return [$exam, $o, collect($students)];
    }

    private function students(int $n)
    {
        return User::factory()->count($n)->create(['user_type' => 'student'])->each->assignRole('student');
    }

    public function test_generated_timetable_has_no_student_clashes_and_respects_seats(): void
    {
        $this->setUpPlatform();
        $s = $this->students(6);
        [$a] = $this->courseExam('CHM 101', $s->slice(0, 4)->all());
        [$b] = $this->courseExam('PHY 101', $s->slice(2, 4)->all()); // shares two students with A
        [$c] = $this->courseExam('MTH 101', $s->slice(4, 2)->all()); // shares with B only
        $hall = Venue::create(['name' => 'Main hall', 'capacity' => 3]);
        $annex = Venue::create(['name' => 'Annex', 'capacity' => 2]);
        $day = now()->addDays(3)->toDateString();

        $this->actingAs($this->officer)->post('/exam-calendar/generate', [
            'exam_ids' => [$a->id, $b->id, $c->id], 'from' => $day, 'to' => now()->addDays(10)->toDateString(), 'slots' => ['09:00', '13:00'],
            'skip_weekends' => false, 'mode' => 'hall', 'venue_ids' => [$hall->id, $annex->id], 'max_per_day' => 1,
        ])->assertRedirect();

        $run = DB::table('timetable_runs')->latest('id')->first();
        $proposal = collect(json_decode($run->proposal, true))->keyBy('exam_id');
        $this->assertCount(3, $proposal);
        $this->assertNotSame(substr($proposal[$a->id]['starts_at'], 0, 10), substr($proposal[$b->id]['starts_at'], 0, 10)); // shared students, one exam a day
        $this->assertSame(4, collect($proposal[$a->id]['venues'])->sum('seats')); // split across halls

        $this->actingAs($this->officer)->post("/exam-calendar/runs/{$run->id}/commit")->assertRedirect('/exam-calendar');
        $this->assertSame(2, ExamSession::where('exam_id', $a->id)->count());
        $this->assertSame(4, ExamCandidate::whereIn('exam_session_id', ExamSession::where('exam_id', $a->id)->pluck('id'))->count());
        $clashes = app(\App\Services\Exams\TimetableClashes::class)->between(now()->toDateTimeString(), now()->addDays(11)->toDateTimeString());
        $this->assertCount(0, $clashes['candidates']);
        $this->assertCount(0, $clashes['venues']);
        // a proposal can only be used once
        $this->actingAs($this->officer)->post("/exam-calendar/runs/{$run->id}/commit")->assertStatus(409);
        $this->actingAs($this->officer)->get('/exam-calendar?month='.substr($day, 0, 7))->assertOk();
    }

    public function test_bulk_move_skips_started_sittings_and_tells_candidates(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam();
        $later = $this->sitting($exam, ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2), 'status' => 'scheduled']);
        $c = $this->candidate($later);
        $live = $this->sitting($exam);
        $this->startAs($this->candidate($live))->assertOk();
        $before = $later->starts_at;

        $this->actingAs($this->officer)->post('/exam-calendar/bulk', ['ids' => [$later->id, $live->id], 'action' => 'shift', 'minutes' => 120, 'reason' => 'Hall maintenance'])->assertRedirect();

        $this->assertEquals($before->addMinutes(120)->getTimestamp(), $later->fresh()->starts_at->getTimestamp());
        $this->assertSame(1, $c->user->notifications()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'sessions.bulk_shift']);
    }

    public function test_attendance_rule_and_resit_eligibility(): void
    {
        $this->setUpPlatform();
        [$present, $absent, $passer] = $this->students(3)->all();
        [$exam, $o] = $this->courseExam('CHM 101', [$present, $absent, $passer]);
        foreach (range(1, 4) as $i) {
            $m = DB::table('class_meetings')->insertGetId(['tenant_id' => tenant_id(), 'course_offering_id' => $o->id, 'held_at' => now()->subDays($i), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('attendance_records')->insert(['class_meeting_id' => $m, 'user_id' => $present->id, 'status' => 'present', 'created_at' => now(), 'updated_at' => now()]);
            $i <= 3 && DB::table('attendance_records')->insert(['class_meeting_id' => $m, 'user_id' => $passer->id, 'status' => 'present', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->setPolicy(['min_attendance_pct' => 75]);
        $session = $this->sitting($exam);

        $this->actingAs($this->officer)->post("/sessions/{$session->id}/candidates", ['from_enrolment' => true])->assertRedirect();
        $verdict = ExamCandidate::where('exam_session_id', $session->id)->get()->keyBy('user_id');
        $this->assertSame('eligible', $verdict[$present->id]->eligibility);
        $this->assertSame('eligible', $verdict[$passer->id]->eligibility); // 3 of 4 = 75%
        $this->assertSame('ineligible', $verdict[$absent->id]->eligibility);
        $this->assertStringContainsString('attendance', $verdict[$absent->id]->eligibility_reason);
        $this->startAs($verdict[$absent->id])->assertForbidden();

        // results of the original: one failed, one passed, one never sat
        Result::create(['exam_id' => $exam->id, 'user_id' => $present->id, 'final_score' => 20, 'status' => 'published']);
        Result::create(['exam_id' => $exam->id, 'user_id' => $passer->id, 'final_score' => 75, 'status' => 'published']);
        $exam->update(['status' => 'closed']);
        $this->actingAs($this->officer)->post("/exams/{$exam->id}/follow-up", ['kind' => 'resit'])->assertRedirect();
        $resit = Exam::where('parent_exam_id', $exam->id)->firstOrFail();
        $this->assertSame('resit', $resit->kind);
        $this->assertSame($exam->sections()->first()->questions()->count(), $resit->sections()->first()->questions()->count());

        $this->setPolicy(['min_attendance_pct' => null, 'resit_max_grade' => 'C']);
        $rs = $this->sitting($resit);
        $this->actingAs($this->officer)->post("/sessions/{$rs->id}/candidates", ['from_parent' => true])->assertRedirect();
        $this->assertSame([$present->id], ExamCandidate::where('exam_session_id', $rs->id)->pluck('user_id')->all());
        $this->actingAs($this->officer)->post("/sessions/{$rs->id}/candidates", ['identifiers' => $passer->email])->assertRedirect();
        $this->assertSame('ineligible', ExamCandidate::where('exam_session_id', $rs->id)->where('user_id', $passer->id)->value('eligibility'));
    }

    public function test_exam_slips_are_private_and_verifiable(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->cbtExam()));
        $other = User::factory()->create(['user_type' => 'student']);
        $other->assignRole('student');

        $this->actingAs($other)->get("/my/documents/exam-slip/{$c->id}")->assertNotFound();
        $this->actingAs($c->user)->get("/my/documents/exam-slip/{$c->id}")->assertOk()->assertHeader('content-type', 'application/pdf');
        $code = DB::table('issued_documents')->where('type', 'exam_slip')->value('code');
        $this->get("/verify/$code")->assertOk();
        $this->actingAs($this->officer)->get("/sessions/{$c->exam_session_id}/slips")->assertOk();
        $this->actingAs($c->user)->get("/sessions/{$c->exam_session_id}/slips")->assertForbidden();
    }

    public function test_chief_is_alerted_once_when_a_proctor_goes_offline_and_can_hand_over(): void
    {
        $this->setUpPlatform();
        $session = $this->sitting($this->cbtExam(), ['starts_at' => now()->subMinutes(20)]);
        $chief = User::factory()->create();
        $chief->assignRole('chief_invigilator');
        $away = User::factory()->create(['name' => 'Away Proctor']);
        $away->assignRole('proctor');
        $here = User::factory()->create();
        $here->assignRole('proctor');
        ProctorAssignment::create(['exam_session_id' => $session->id, 'user_id' => $chief->id, 'role' => 'chief']);
        $gone = ProctorAssignment::create(['exam_session_id' => $session->id, 'user_id' => $away->id, 'role' => 'proctor', 'last_seen_at' => now()->subMinutes(5)]);
        ProctorAssignment::create(['exam_session_id' => $session->id, 'user_id' => $here->id, 'role' => 'proctor', 'last_seen_at' => now()]);
        $c = $this->candidate($session);
        DB::table('proctor_candidate')->insert(['proctor_assignment_id' => $gone->id, 'exam_candidate_id' => $c->id]);

        $this->assertSame(1, app(ProctorWatch::class)->run());
        $this->assertSame(0, app(ProctorWatch::class)->run()); // not again for the same disconnection
        $this->assertStringContainsString('Away Proctor', $chief->notifications()->first()->data['title']);

        $this->actingAs($chief)->postJson("/proctor/sessions/{$session->id}/action", ['action' => 'cover'])->assertOk();
        $this->assertSame(ProctorAssignment::where('user_id', $here->id)->value('id'), DB::table('proctor_candidate')->where('exam_candidate_id', $c->id)->value('proctor_assignment_id'));
    }

    public function test_training_sandbox_tracks_tasks_and_cleans_up(): void
    {
        $this->setUpPlatform();
        $p = User::factory()->create();
        $p->assignRole('proctor');

        $this->actingAs($p)->post('/proctor/training')->assertRedirect();
        $session = ExamSession::where('is_practice', true)->latest('id')->firstOrFail();
        $this->actingAs($p)->getJson("/proctor/sessions/{$session->id}/live")->assertOk()->assertJsonCount(6, 'candidates');
        $attempts = \App\Models\Attempt::where('exam_session_id', $session->id)->get();
        $help = \App\Models\CandidateMessage::where('kind', 'help')->first();
        $locked = $attempts->firstWhere('status', 'locked');

        $this->actingAs($p)->postJson("/proctor/attempts/{$help->attempt_id}/action", ['action' => 'ack_help', 'message_id' => $help->id])->assertOk();
        $this->actingAs($p)->postJson("/proctor/attempts/{$attempts->first()->id}/action", ['action' => 'warn', 'body' => 'Eyes on screen'])->assertOk();
        $this->actingAs($p)->postJson("/proctor/attempts/{$locked->id}/action", ['action' => 'unlock'])->assertOk();
        $this->actingAs($p)->post('/incidents', ['attempt_id' => $locked->id, 'type' => 'malpractice', 'severity' => 'low', 'title' => 'Practice'])->assertRedirect();
        $this->assertNotNull(DB::table('proctor_trainings')->where('user_id', $p->id)->value('completed_at'));

        // simulated candidates can never sign in
        $sim = User::where('user_type', 'sandbox')->first();
        $this->assertSame('inactive', $sim->status);
        auth()->guard('web')->logout();
        $this->post('/login', ['email' => $sim->email, 'password' => 'anything'])->assertSessionHasErrors();
        $this->assertGuest();

        $this->actingAs($p)->delete("/proctor/training/{$session->id}")->assertRedirect();
        $this->assertSame(0, User::where('user_type', 'sandbox')->count());
    }

    public function test_students_cannot_see_or_open_incidents_and_proctors_only_see_their_own(): void
    {
        $this->setUpPlatform();
        $mine = $this->sitting($this->cbtExam());
        $theirs = $this->sitting($this->cbtExam());
        $p = User::factory()->create();
        $p->assignRole('proctor');
        ProctorAssignment::create(['exam_session_id' => $mine->id, 'user_id' => $p->id]);
        $other = Incident::create(['exam_session_id' => $theirs->id, 'type' => 'other', 'severity' => 'low', 'status' => 'open', 'source' => 'proctor', 'title' => 'Elsewhere']);
        $student = User::factory()->create(['user_type' => 'student']);
        $student->assignRole('student');

        $this->actingAs($student)->get('/incidents')->assertForbidden();
        $this->actingAs($student)->post('/incidents', ['exam_session_id' => $mine->id, 'type' => 'other', 'severity' => 'low', 'title' => 'x'])->assertForbidden();
        $this->actingAs($p)->get("/incidents/{$other->id}")->assertForbidden();
        $this->actingAs($p)->post('/incidents', ['exam_session_id' => $theirs->id, 'type' => 'other', 'severity' => 'low', 'title' => 'x'])->assertForbidden();
        $this->actingAs($p)->post('/incidents', ['exam_session_id' => $mine->id, 'type' => 'other', 'severity' => 'low', 'title' => 'Mine'])->assertRedirect();
        $this->actingAs($p)->get('/incidents')->assertOk()->assertInertia(fn ($page) => $page->has('incidents.data', 1)->where('incidents.data.0.title', 'Mine'));
    }

    public function test_proctor_layout_is_saved_per_person(): void
    {
        $this->setUpPlatform();
        $p = User::factory()->create();
        $p->assignRole('proctor');
        $session = $this->sitting($this->cbtExam());
        ProctorAssignment::create(['exam_session_id' => $session->id, 'user_id' => $p->id]);

        $this->actingAs($p)->putJson('/proctor/layout', ['columns' => 6, 'cameras' => false, 'sort' => 'seat', 'only_flagged' => true, 'compact' => true])->assertOk();
        $this->actingAs($p)->putJson('/proctor/layout', ['columns' => 99, 'cameras' => false, 'sort' => 'seat', 'only_flagged' => true, 'compact' => true])->assertUnprocessable();
        $this->actingAs($p)->get("/proctor/sessions/{$session->id}")->assertInertia(fn ($page) => $page->where('layout.columns', 6)->where('layout.sort', 'seat'));
    }
}
