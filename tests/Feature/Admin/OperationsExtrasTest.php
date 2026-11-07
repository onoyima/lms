<?php

namespace Tests\Feature\Admin;

use App\Models\Attempt;
use App\Models\AttemptAnomaly;
use App\Models\ClassMeeting;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\ExceptionRequest;
use App\Models\HardwareException;
use App\Models\MailLog;
use App\Models\PlagiarismCheck;
use App\Models\SettingsVersion;
use App\Models\User;
use App\Models\Venue;
use App\Services\Analytics\AnomalyModel;
use App\Services\Integrations\Connectors;
use App\Support\Exams\ExamSettings;
use App\Support\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class OperationsExtrasTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        $this->officer->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    private function staff(string $role): User
    {
        $u = User::factory()->create(['user_type' => 'staff', 'two_factor_confirmed_at' => now()]);
        $u->assignRole($role);

        return $u;
    }

    private function student(): User
    {
        $u = User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/'.fake()->unique()->numerify('####')]);
        $u->assignRole('student');

        return $u;
    }

    private function policyPayload(array $over = []): array
    {
        return array_replace(Policy::all(), $over);
    }

    /* settings history, rollback and campus settings (Target 170, 99) */

    public function test_policy_versions_are_kept_and_can_be_rolled_back(): void
    {
        $this->actingAs($this->officer)->put('/admin/policy', $this->policyPayload(['max_carryover_units' => 9]))->assertRedirect();
        $this->actingAs($this->officer)->put('/admin/policy', $this->policyPayload(['max_carryover_units' => 12]))->assertRedirect();
        $this->assertSame(12, Policy::get('max_carryover_units'));
        $first = SettingsVersion::where('scope', 'policy')->orderBy('id')->firstOrFail();

        $this->actingAs($this->officer)->post("/admin/policy/versions/{$first->id}/restore")->assertRedirect();
        $this->assertSame(9, (int) Policy::get('max_carryover_units'));
        $this->assertSame($first->id, SettingsVersion::latest('id')->first()->restored_from);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.restored']);
    }

    public function test_a_campus_can_override_its_own_settings_only(): void
    {
        Venue::create(['name' => 'Hall A', 'campus' => 'Gwagwalada Campus', 'capacity' => 100]);
        $this->actingAs($this->officer)->put('/admin/policy', $this->policyPayload(['incident_contacts' => [['role' => 'ICT', 'name' => 'Main desk', 'phone' => '0800']]]))->assertRedirect();
        $this->actingAs($this->officer)->put('/admin/policy/campus/gwagwalada_campus', [
            'incident_contacts' => [['role' => 'ICT', 'name' => 'Campus desk', 'phone' => '0801']], 'exam_day_notice' => 'Use gate B.', 'grading_scale' => ['A' => 1],
        ])->assertRedirect();

        $this->assertSame('Campus desk', Policy::forCampus('Gwagwalada Campus')['incident_contacts'][0]['name']);
        $this->assertSame('Main desk', Policy::forCampus(null)['incident_contacts'][0]['name']);
        $this->assertSame(Policy::all()['grading_scale'], Policy::forCampus('Gwagwalada Campus')['grading_scale']); // not campus-level
        $this->actingAs($this->officer)->put('/admin/policy/campus/nowhere', ['exam_day_notice' => 'x'])->assertNotFound();
    }

    /* hardware exceptions (Target 141) */

    public function test_a_hardware_exception_needs_a_second_person_and_then_relaxes_the_checks(): void
    {
        $exam = $this->cbtExam(['proctoring' => ['webcam' => true, 'face_detection' => true, 'require_consent' => false]]);
        $student = $this->student();
        $this->actingAs($this->officer)->post('/admin/hardware-exceptions', ['identifier' => $student->identifier, 'exam_id' => $exam->id, 'kind' => 'no_webcam',
            'detail' => 'No working webcam; disability office letter seen.', 'relax' => ['webcam', 'face_detection']])->assertRedirect();
        $h = HardwareException::sole();
        $this->assertTrue(ExamSettings::forCandidate($exam->proctoring, $student->id, $exam->id)['webcam']); // not yet approved

        $this->actingAs($this->officer)->post("/admin/hardware-exceptions/{$h->id}", ['action' => 'approve'])->assertForbidden();
        $officer2 = $this->staff('exam_officer');
        $this->actingAs($officer2)->post("/admin/hardware-exceptions/{$h->id}", ['action' => 'approve'])->assertRedirect();

        $p = ExamSettings::forCandidate($exam->proctoring, $student->id, $exam->id);
        $this->assertFalse($p['webcam']);
        $this->assertFalse($p['face_detection']);
        $this->assertTrue(ExamSettings::forCandidate($exam->proctoring, $this->student()->id, $exam->id)['webcam']); // others unchanged
    }

    /* exception requests (LMS 178) */

    public function test_students_request_exceptions_and_staff_decide_with_reasons(): void
    {
        $s = $this->student();
        $this->actingAs($s)->post('/my/exceptions', ['type' => 'missed_exam', 'subject' => 'Missed CHM 101', 'reason' => 'I was admitted to hospital on the morning of the exam.',
            'evidence' => UploadedFile::fake()->create('letter.pdf', 50, 'application/pdf')])->assertRedirect();
        $r = ExceptionRequest::sole();
        $this->assertNotNull($r->evidence_path);
        $this->assertStringNotContainsString('%PDF', \Illuminate\Support\Facades\Storage::disk('local')->get($r->evidence_path)); // encrypted at rest

        $this->actingAs($this->student())->get("/exceptions/{$r->id}/evidence")->assertForbidden();
        $this->actingAs($s)->get('/admin/exceptions')->assertForbidden();
        $this->actingAs($s)->post("/admin/exceptions/{$r->id}/decide", ['approve' => true, 'note' => 'ok then'])->assertForbidden();

        $this->actingAs($this->officer)->get("/exceptions/{$r->id}/evidence")->assertOk();
        $this->actingAs($this->officer)->post("/admin/exceptions/{$r->id}/decide", ['approve' => true, 'note' => 'Hospital letter confirmed; resit scheduled.'])->assertRedirect();
        $this->assertSame('approved', $r->fresh()->status);
        $this->actingAs($this->officer)->post("/admin/exceptions/{$r->id}/decide", ['approve' => false, 'note' => 'changed mind'])->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exception.evidence_viewed']);
    }

    /* e-mail delivery (LMS 188) */

    public function test_email_delivery_is_logged_and_bounces_are_recorded(): void
    {
        config(['mail.default' => 'array', 'platform.mail_webhook_secret' => 'whsec_test_123']);
        Mail::raw('Hello', fn ($m) => $m->to('ada@uni.edu.ng')->subject('Exam timetable'));
        $log = MailLog::sole();
        $this->assertSame('sent', $log->status);
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertNotNull($log->message_id);

        $body = json_encode(['message_id' => $log->message_id, 'event' => 'bounced', 'reason' => 'Mailbox full']);
        $this->call('POST', '/webhooks/mail', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PLATFORM_SIGNATURE' => 'bad'], $body)->assertStatus(401);
        $this->call('POST', '/webhooks/mail', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PLATFORM_SIGNATURE' => hash_hmac('sha256', $body, 'whsec_test_123')], $body)->assertOk();
        $this->assertSame('bounced', $log->fresh()->status);
        $this->actingAs($this->officer)->get('/admin/mail-log')->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 1));
    }

    /* dashboards (LMS 200) */

    public function test_each_person_arranges_their_own_dashboard(): void
    {
        $this->actingAs($this->officer)->put('/dashboard/layout', ['hidden' => ['modules', 'kpi:students'], 'order' => ['upcoming', 'kpis']])->assertRedirect();
        $this->actingAs($this->officer)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('layout.hidden', ['modules', 'kpi:students'])->where('layout.order', ['upcoming', 'kpis']));
        $this->actingAs($this->officer)->put('/dashboard/layout', ['hidden' => ['<script>']])->assertSessionHasErrors();
    }

    /* connectors (LMS 221, 222, 224) */

    private function apiKey(array $scopes): string
    {
        $this->actingAs($this->officer)->post('/admin/integrations/keys', ['name' => 'Gate readers', 'scopes' => $scopes, 'days' => 30])->assertRedirect();

        return session('new_key');
    }

    private function offeringWith(User $student): CourseOffering
    {
        $session = \App\Models\AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $sem = \App\Models\Semester::create(['academic_session_id' => $session->id, 'name' => 'First semester', 'starts_on' => now()->subMonth(), 'ends_on' => now()->addMonths(3), 'is_current' => true]);
        $course = Course::create(['code' => 'CHM 101', 'title' => 'General Chemistry I', 'credit_units' => 3]);
        $o = CourseOffering::create(['course_id' => $course->id, 'semester_id' => $sem->id, 'status' => 'open']);
        DB::table('enrolments')->insert(['tenant_id' => $this->tenant->id, 'course_offering_id' => $o->id, 'user_id' => $student->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $o;
    }

    public function test_attendance_systems_can_send_attendance_with_a_scoped_key(): void
    {
        $s = $this->student();
        $o = $this->offeringWith($s);
        $key = $this->apiKey(['attendance.write']);
        $records = ['records' => [
            ['course_code' => 'chm 101', 'identifier' => $s->identifier, 'held_at' => now()->setTime(9, 2)->toIso8601String(), 'device' => 'Gate 3'],
            ['course_code' => 'CHM 101', 'identifier' => 'NOBODY', 'held_at' => now()->toIso8601String()],
        ]];
        $this->withHeaders(['Authorization' => "Bearer $key"])->postJson('/api/v1/attendance', $records)->assertOk()
            ->assertJsonPath('recorded', 1)->assertJsonPath('meetings_created', 1)->assertJsonCount(1, 'errors');
        $this->assertSame('import', DB::table('attendance_records')->value('source'));
        $this->assertSame($o->id, ClassMeeting::sole()->course_offering_id);
        $this->withHeaders(['Authorization' => "Bearer $key"])->getJson('/api/v1/analytics')->assertForbidden(); // wrong scope
    }

    public function test_the_analytics_feed_has_course_figures_and_no_names(): void
    {
        $s = $this->student();
        $this->offeringWith($s);
        $key = $this->apiKey(['analytics.read']);
        $res = $this->withHeaders(['Authorization' => "Bearer $key"])->getJson('/api/v1/analytics')->assertOk();
        $this->assertSame(1, $res->json('datasets.enrolments.0.students'));
        $this->assertStringNotContainsString($s->name, $res->getContent());
        $this->assertStringNotContainsString((string) $s->identifier, $res->getContent());
    }

    public function test_the_plagiarism_service_gets_the_submission_and_its_score_comes_back_signed(): void
    {
        $s = $this->student();
        $o = $this->offeringWith($s);
        $section = \App\Models\CourseSection::create(['course_offering_id' => $o->id, 'title' => 'Week 1', 'position' => 0]);
        $item = \App\Models\CourseItem::create(['course_offering_id' => $o->id, 'course_section_id' => $section->id, 'type' => 'assignment', 'title' => 'Essay 1', 'visible' => true, 'created_by' => $this->officer->id]);
        $sub = \App\Models\AssignmentSubmission::create(['course_item_id' => $item->id, 'user_id' => $s->id, 'attempt' => 1, 'anon_code' => 'X7K2', 'text' => 'Osmosis is the movement of water...', 'status' => 'submitted', 'submitted_at' => now()]);
        app(Connectors::class)->save('plagiarism', ['enabled' => true, 'name' => 'TurnCheck', 'url' => 'https://93.184.216.34/check', 'secret' => str_repeat('s', 32)]);
        Http::fake(['93.184.216.34/*' => Http::response(['id' => 'ext-1', 'status' => 'queued'])]);

        $this->actingAs($this->officer)->post("/learn/submissions/{$sub->id}/plagiarism")->assertRedirect();
        Http::assertSent(fn ($r) => $r->hasHeader('X-Platform-Signature', hash_hmac('sha256', $r->body(), str_repeat('s', 32))) && $r['submission']['text'] === 'Osmosis is the movement of water...');
        $check = PlagiarismCheck::sole();
        $this->assertSame('sent', $check->status);

        $body = json_encode(['status' => 'complete', 'score' => 23.5, 'report_url' => 'https://plag.example.com/r/1']);
        $this->call('POST', "/api/v1/plagiarism/callback/{$check->token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PLATFORM_SIGNATURE' => 'nope'], $body)->assertStatus(401);
        $this->call('POST', "/api/v1/plagiarism/callback/{$check->token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PLATFORM_SIGNATURE' => hash_hmac('sha256', $body, str_repeat('s', 32))], $body)->assertOk();
        $this->assertSame(23.5, $check->fresh()->score);
        $this->assertSame('complete', $check->fresh()->status);
    }

    public function test_connector_addresses_must_be_public_https(): void
    {
        $this->actingAs($this->officer)->put('/admin/integrations/analytics', ['enabled' => true, 'url' => 'https://10.0.0.5/feed', 'secret' => str_repeat('k', 30)])->assertStatus(422);
        $this->actingAs($this->staff('lecturer'))->get('/admin/integrations')->assertForbidden();
    }

    /* statistical anomaly review (Target 171) */

    public function test_an_unusual_attempt_is_flagged_for_a_person_and_can_be_referred(): void
    {
        Notification::fake();
        $exam = $this->cbtExam();
        $session = $this->sitting($exam, ['starts_at' => now()->subHours(3), 'ends_at' => now()->subHour()]);
        $q = $exam->sections()->first()->questions()->pluck('questions.id')->all();
        foreach (range(1, 12) as $n) {
            $c = $this->candidate($session);
            $odd = $n === 12;
            $mins = $odd ? 4 : 25 + ($n % 5);
            $a = Attempt::create(['exam_id' => $exam->id, 'exam_session_id' => $session->id, 'exam_candidate_id' => $c->id, 'user_id' => $c->user_id, 'attempt_no' => 1, 'status' => 'submitted',
                'started_at' => now()->subHours(3), 'deadline_at' => now()->subHours(2), 'submitted_at' => now()->subHours(3)->addMinutes($mins), 'score_raw' => $odd ? 9 : 3 + ($n % 4), 'score_max' => 9, 'risk_score' => $odd ? 40 : $n % 3]);
            foreach ($q as $i => $qid) {
                DB::table('attempt_questions')->insert(['attempt_id' => $a->id, 'question_id' => $qid, 'position' => $i, 'mark' => 1]);
                DB::table('attempt_answers')->insert(['attempt_id' => $a->id, 'question_id' => $qid, 'response' => json_encode(['options' => [1]]),
                    'score' => $odd || ($n + $i) % 3 === 0 ? 1 : 0, 'answered_at' => now()->subHours(3)->addMinutes($odd ? $mins : 5 * ($i + 1))]);
            }
        }
        $r = app(AnomalyModel::class)->run($exam);
        $this->assertSame(12, $r['attempts']);
        $flag = AttemptAnomaly::where('review', 'open')->orderByDesc('score')->firstOrFail();
        $this->assertSame(12, Attempt::find($flag->attempt_id)->candidate->user->id - User::where('user_type', 'student')->min('id') + 1);
        $this->assertNotEmpty($flag->reasons);

        $this->actingAs($this->officer)->post("/anomalies/{$flag->id}", ['action' => 'refer'])->assertRedirect();
        $this->assertDatabaseHas('incidents', ['attempt_id' => $flag->attempt_id, 'source' => 'analytics', 'needs_human_review' => true]);
        $this->assertSame('referred', $flag->fresh()->review);
    }

    public function test_the_review_needs_enough_attempts(): void
    {
        $this->assertArrayHasKey('skipped', app(AnomalyModel::class)->run($this->cbtExam()));
    }

    /* procedures (exams office) */

    public function test_new_institutions_get_the_written_procedures_and_compatibility_list(): void
    {
        $this->assertTrue(\App\Models\HelpArticle::where('slug', 'exam-incidents-procedure')->where('audience', 'staff')->exists());
        $this->assertTrue(\App\Models\HelpArticle::where('slug', 'exam-flags-and-appeals')->exists());
        $this->actingAs($this->student())->get('/help/exam-incidents-procedure')->assertNotFound(); // staff only
        $this->actingAs($this->student())->get('/help/device-compatibility')->assertOk();
    }

    public function test_the_security_check_runs_and_reports(): void
    {
        $this->artisan('security:check')->assertExitCode(1); // scheduler/backups are not running in tests
        $this->assertNotEmpty(\Illuminate\Support\Facades\Cache::get('security:check')['results']);
    }
}
