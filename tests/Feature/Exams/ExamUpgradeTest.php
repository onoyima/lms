<?php

namespace Tests\Feature\Exams;

use App\Http\Middleware\SingleStudentSession;
use App\Models\Attempt;
use App\Models\Exam;
use App\Models\ExamComputer;
use App\Models\MediaRecording;
use App\Models\Result;
use App\Models\User;
use App\Services\Exams\ExamComputers;
use App\Services\Media\LiveKit;
use App\Services\Questions\Import\DocumentConverter;
use App\Services\Questions\Import\WordScienceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Exam upgrade: late start rule, re-attempts, one sign-in per student, research exams, approved
 * computers by IP, recordings that carry on, the exam board with rescaling and examiner access,
 * and the science & maths question reader.
 */
class ExamUpgradeTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    // ── starting late ─────────────────────────────────────────────────────────

    public function test_late_start_can_be_allowed_or_cut_off(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam(['settings' => ['late_start' => 'cutoff', 'late_start_minutes' => 30, 'require_check_in' => false]]);
        $session = $this->sitting($exam, ['starts_at' => now()->subMinutes(40), 'late_entry_minutes' => 60]);
        $this->startAs($this->candidate($session))->assertForbidden()->assertJsonFragment(['reason' => 'late'])->assertSee('30 minutes');

        $exam->update(['settings' => ['late_start' => 'any_time', 'require_check_in' => false]]);
        $session->update(['late_entry_minutes' => 15]); // the sitting alone would refuse at 40 minutes
        $this->startAs($this->candidate($session))->assertOk();

        $exam->update(['settings' => ['late_start' => 'sitting', 'require_check_in' => false]]);
        $this->startAs($this->candidate($session))->assertForbidden();
    }

    public function test_a_student_with_attempts_left_sees_re_attempt(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->cbtExam(['max_attempts' => 3])));
        $token = $this->startAs($c)->json('token');
        $this->api($token, 'POST', 'submit')->assertOk();

        $this->actingAs($c->user)->get('/my/exams')->assertOk()->assertInertia(fn ($p) => $p->where('exams.0.attempts_used', 1)->where('exams.0.can_reattempt', true)->where('exams.0.has_open_attempt', false));
        $this->actingAs($c->user)->get("/my/exams/{$c->id}")->assertInertia(fn ($p) => $p->where('candidate.can_reattempt', true)->where('candidate.max_attempts', 3));
        $this->startAs($c)->assertOk()->assertJson(['resumed' => false]);
        $this->assertSame(2, Attempt::where('exam_candidate_id', $c->id)->max('attempt_no'));
    }

    public function test_submitting_with_the_score_shown_keeps_the_student_signed_in(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->cbtExam(['type' => 'cbt'], array_slice($this->questions(), 0, 1))));
        $token = $this->startAs($c)->json('token');
        $this->api($token, 'POST', 'submit')->assertOk()->assertJsonStructure(['score' => ['raw', 'max']]);
        $this->actingAs($c->user)->get('/my/exams')->assertOk();
        $this->assertAuthenticatedAs($c->user);
    }

    // ── one sign-in per student ──────────────────────────────────────────────

    public function test_a_new_sign_in_ends_the_older_one_and_stops_an_exam_left_open(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->cbtExam()));
        $c->user->forceFill(['password' => bcrypt('Secret-pass-123'), 'email' => 'stud@vu.test'])->save();
        $token = $this->startAs($c)->json('token');
        $old = $this->app['session']->get('login_token');
        $this->assertNotNull(Cache::get(SingleStudentSession::key($c->user_id)));

        // the same student signs in on another computer
        $this->flushSession();
        auth()->guard('web')->logout();
        $this->post('/login', ['email' => 'stud@vu.test', 'password' => 'Secret-pass-123'])->assertRedirect();
        $this->assertNotSame($old, Cache::get(SingleStudentSession::key($c->user_id)));

        // the first window's exam stops (opening it here resumes it with the answers kept)
        $this->api($token, 'POST', 'heartbeat', ['status' => 'active'])->assertStatus(409)->assertJson(['reason' => 'session_moved']);
        $this->assertDatabaseHas('proctoring_events', ['attempt_id' => Attempt::first()->id, 'type' => 'signed_in_elsewhere']);

        // and the first browser session is signed out on its next request
        $this->flushSession();
        $this->withSession(['login_token' => $old])->actingAs($c->user)->get('/my/exams')->assertRedirect('/login');
        $this->assertGuest();
    }

    // ── research exams ─────────────────────────────────────────────────────────

    public function test_research_exams_are_scored_by_examiners_up_to_the_maximum(): void
    {
        $this->setUpPlatform();
        $this->actingAs($this->officer)->post('/exams', ['title' => 'Final year project', 'type' => 'research', 'total_mark' => 70])->assertRedirect();
        $exam = Exam::where('type', 'research')->firstOrFail();
        $this->assertTrue($exam->isPanelMarked());
        $criterion = $exam->criteria()->first();
        $this->assertNull($criterion->allowed_scores);
        $this->assertSame(70.0, (float) $criterion->max_score);

        $exam->update(['status' => 'scheduled']);
        $c = $this->candidate($this->sitting($exam));
        $exam->examiners()->attach($this->officer->id, ['role' => 'examiner']);
        $this->actingAs($this->officer)->post("/marking/{$exam->id}/panel", ['user_id' => $c->user_id, 'scores' => [$criterion->id => 55]])->assertRedirect();
        $this->assertSame(55.0, (float) Result::where('exam_id', $exam->id)->value('raw_score'));
        $this->actingAs($this->officer)->get("/marking/{$exam->id}/panel")->assertOk()->assertInertia(fn ($p) => $p->component('marking/panel')->where('criteria.0.max_score', fn ($v) => (float) $v === 70.0));
    }

    // ── approved computers by IP ───────────────────────────────────────────────

    public function test_hall_computers_must_be_approved_and_the_list_is_wiped_but_kept(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => false, 'ip_approval' => true]]);
        $session = $this->sitting($exam, ['mode' => 'hall']);
        $c = $this->candidate($session);

        $this->startAs($c, [], ['REMOTE_ADDR' => '10.10.4.21'])->assertForbidden()->assertJson(['reason' => 'computer_pending']);
        $pc = ExamComputer::firstOrFail();
        $this->assertSame(['10.10.4.21', 'pending', $c->user_id], [$pc->ip, $pc->status, $pc->last_user_id]);

        $this->actingAs($this->officer)->get('/exam-network')->assertOk()->assertInertia(fn ($p) => $p->component('exams/network')->where('counts.pending', 1));
        $this->actingAs($this->officer)->put("/exam-network/computers/{$pc->id}", ['status' => 'approved'])->assertRedirect();
        $this->startAs($c, [], ['REMOTE_ADDR' => '10.10.4.21'])->assertOk();

        // a refused computer stays refused
        $other = $this->candidate($session);
        $this->startAs($other, [], ['REMOTE_ADDR' => '10.10.4.22'])->assertForbidden();
        $this->actingAs($this->officer)->put('/exam-network/computers/'.ExamComputer::where('ip', '10.10.4.22')->value('id'), ['status' => 'rejected']);
        $this->startAs($other, [], ['REMOTE_ADDR' => '10.10.4.22'])->assertForbidden()->assertJson(['reason' => 'computer_rejected']);

        // reset: nothing deleted, everything moved to history
        $this->actingAs($this->officer)->post('/exam-network/reset')->assertRedirect();
        $this->assertSame(0, ExamComputer::current()->count());
        $this->assertSame(2, ExamComputer::whereNotNull('cleared_at')->count());
        $this->actingAs($this->officer)->get('/exam-network')->assertInertia(fn ($p) => $p->where('history.0.approved', 1)->where('history.0.rejected', 1));
        $this->actingAs($this->officer)->getJson('/exam-network/history?at='.urlencode(ExamComputer::first()->cleared_at->toIso8601String()))->assertOk()->assertJsonCount(2);

        // remote sittings are never limited by the hall list
        $remote = $this->candidate($this->sitting($exam, ['mode' => 'remote']));
        $this->startAs($remote, [], ['REMOTE_ADDR' => '41.1.1.1'])->assertOk();
    }

    public function test_approvals_clear_by_themselves_after_the_sitting_ends(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam();
        $this->sitting($exam, ['starts_at' => now()->subHours(3), 'ends_at' => now()->subMinutes(20)]);
        ExamComputer::create(['ip' => '10.0.0.5', 'status' => 'approved', 'decided_at' => now()->subHours(2), 'first_seen_at' => now()->subHours(2)]);
        ExamComputer::create(['ip' => '10.0.0.6', 'status' => 'approved', 'decided_at' => now()->subMinutes(5), 'first_seen_at' => now()->subMinutes(5)]); // for the next exam

        $this->assertSame(1, app(ExamComputers::class)->autoClear());
        $this->assertSame(['10.0.0.6'], ExamComputer::current()->pluck('ip')->all());
        $this->assertStringContainsString('CHM 101', ExamComputer::whereNotNull('cleared_at')->value('clear_reason'));

        // not while another sitting is running
        ExamComputer::create(['ip' => '10.0.0.7', 'status' => 'approved', 'decided_at' => now()->subHours(1), 'first_seen_at' => now()->subHours(1)]);
        $this->sitting($exam);
        $this->assertSame(0, app(ExamComputers::class)->autoClear());
    }

    public function test_the_address_students_use_can_be_chosen(): void
    {
        $this->setUpPlatform();
        $this->actingAs($this->officer)->put('/exam-network', ['preferred_address' => '10.10.0.109', 'auto_clear' => true])->assertRedirect();
        $this->assertContains('10.10.0.109', $this->tenant->fresh()->domains);
        $this->assertSame($this->tenant->id, \App\Models\Tenant::findByHost('10.10.0.109')?->id); // the platform answers for this institution there

        $this->actingAs($this->officer)->put('/exam-network', ['preferred_address' => '10.10.0.110', 'auto_clear' => false])->assertRedirect();
        $domains = $this->tenant->fresh()->domains;
        $this->assertContains('10.10.0.110', $domains);
        $this->assertNotContains('10.10.0.109', $domains); // only what this page added is taken away
        $this->assertFalse(ExamComputers::settings($this->tenant->fresh())['auto_clear']);

        $this->actingAs($this->officer)->put('/exam-network', ['preferred_address' => 'not an address!', 'auto_clear' => true])->assertSessionHasErrors('preferred_address');
        $this->actingAs($this->officer)->get('/exam-network')->assertOk()->assertInertia(fn ($p) => $p->where('studentUrl', fn ($u) => str_contains($u, '10.10.0.110')));
    }

    // ── recordings that carry on ───────────────────────────────────────────────

    public function test_recording_carries_on_in_a_new_part_after_an_interruption_and_stops_at_submit(): void
    {
        config(['platform.livekit' => ['url' => 'wss://media.test', 'api_url' => 'https://media.test', 'key' => 'APIkey1', 'secret' => str_repeat('s', 40), 'recordings_path' => '/recordings']]);
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->cbtExam(['proctoring' => ['require_consent' => false, 'live_video' => true, 'record_video' => true]])));
        $token = $this->startAs($c)->json('token');
        $a = Attempt::first();
        $room = 'vt'.$a->tenant_id.'-s'.$a->exam_session_id;
        $n = 0;
        Http::fake(['media.test/twirp/livekit.Egress/StartParticipantEgress' => function () use (&$n) {
            return Http::response(['egress_id' => 'EG_'.(++$n)]);
        }, 'media.test/twirp/livekit.Egress/StopEgress' => Http::response([])]);

        $this->hook(['event' => 'track_published', 'room' => ['name' => $room], 'participant' => ['identity' => "c-{$a->id}"], 'track' => ['source' => 'CAMERA']])->assertOk();
        $this->hook(['event' => 'participant_left', 'room' => ['name' => $room], 'participant' => ['identity' => "c-{$a->id}"]])->assertOk();
        $this->hook(['event' => 'track_published', 'room' => ['name' => $room], 'participant' => ['identity' => "c-{$a->id}"], 'track' => ['source' => 'CAMERA']])->assertOk();
        $this->assertSame(2, MediaRecording::count()); // part 2 after the candidate came back

        // a recording the media server cut off while the candidate is writing starts again
        $this->hook(['event' => 'egress_ended', 'egressInfo' => ['egressId' => 'EG_2', 'roomName' => $room, 'status' => 'EGRESS_LIMIT_REACHED']])->assertOk();
        $this->assertSame(3, MediaRecording::count());

        $this->api($token, 'POST', 'submit')->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'StopEgress') && $r['egress_id'] === 'EG_3');
        $this->assertSame(0, MediaRecording::whereIn('status', ['starting', 'active'])->count());
    }

    private function hook(array $event)
    {
        $body = json_encode($event);
        $token = LiveKit::sign(['iss' => 'APIkey1', 'exp' => time() + 60, 'sha256' => base64_encode(hash('sha256', $body, true))], str_repeat('s', 40));

        return $this->call('POST', '/api/livekit/webhook', [], [], [], ['HTTP_AUTHORIZATION' => $token, 'CONTENT_TYPE' => 'application/webhook+json'], $body);
    }

    // ── exam board ───────────────────────────────────────────────────────────────

    private function lecturer(): User
    {
        $u = User::factory()->create(['user_type' => 'staff', 'name' => 'Dr Lecturer']);
        $u->assignRole('lecturer');

        return $u;
    }

    public function test_the_board_shows_live_counts_scores_and_the_grade_spread(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam(['type' => 'cbt', 'total_mark' => null, 'pass_mark' => 1], array_slice($this->questions(), 0, 2)); // 3 marks
        $session = $this->sitting($exam);
        $writer = $this->candidate($session);
        $this->startAs($writer)->assertOk();
        $done = $this->candidate($session);
        $t = $this->startAs($done)->json('token');
        $paper = $this->api($t, 'GET', 'paper')->json('paper.sections.0.questions');
        $right = collect($paper[0]['options'])->firstWhere('label', 'B')['id'];
        $this->api($t, 'POST', 'submit', ['answers' => [['question_id' => $paper[0]['id'], 'seq' => 1, 'response' => ['options' => [$right]]]]])->assertOk();
        $this->candidate($session); // not started

        $this->actingAs($this->officer)->get('/exam-board?show=active')->assertOk()
            ->assertInertia(fn ($p) => $p->component('exams/board')->where('exams.data.0.writing', 1)->where('exams.data.0.finished', 1)->where('exams.data.0.candidates', 3));

        $this->actingAs($this->officer)->get("/exam-board/{$exam->id}?scale=60")->assertOk()->assertInertia(fn ($p) => $p->component('exams/board-exam')
            ->where('summary.writing', 1)->where('summary.submitted', 1)->where('summary.not_started', 1)
            ->where('board.0.raw', 1)->where('board.0.max', 3)->where('board.0.percent', 33.3)->where('board.0.scaled', 20)
            ->where('board.1.raw', null) // still writing: no score yet
            ->where('distribution.3.count', 1)->where('distribution.3.label', '31–40')->where('scale', 60));

        $csv = $this->actingAs($this->officer)->get("/exam-board/{$exam->id}/export?scale=60&format=csv")->assertOk()->streamedContent();
        $xlsx = $this->actingAs($this->officer)->get("/exam-board/{$exam->id}/export?scale=60")->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $zip = new \ZipArchive;
        $zip->open($xlsx->baseResponse->getFile()->getPathname());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('Score out of 60', $sheet);
        $this->assertStringContainsString('<v>20</v>', $sheet); // a number, not text
        $this->actingAs($this->officer)->get("/exam-board/{$exam->id}/export?scale=60&format=pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Score out of 60', $csv);
        $this->assertStringContainsString(',20,', $csv);
    }

    public function test_the_official_total_can_be_changed_until_results_are_approved(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam(['type' => 'cbt', 'total_mark' => null], array_slice($this->questions(), 0, 1));
        $c = $this->candidate($this->sitting($exam));
        $t = $this->startAs($c)->json('token');
        $q = $this->api($t, 'GET', 'paper')->json('paper.sections.0.questions.0');
        $this->api($t, 'POST', 'submit', ['answers' => [['question_id' => $q['id'], 'seq' => 1, 'response' => ['options' => [collect($q['options'])->firstWhere('label', 'B')['id']]]]]]);

        $this->actingAs($this->officer)->post("/exam-board/{$exam->id}/scale", ['total_mark' => 100])->assertRedirect();
        $this->assertSame(100.0, (float) $exam->fresh()->total_mark);
        $this->assertSame(100.0, Result::where('exam_id', $exam->id)->value('final_score'));

        Result::where('exam_id', $exam->id)->update(['status' => 'approved', 'locked' => true]);
        $this->actingAs($this->officer)->post("/exam-board/{$exam->id}/scale", ['total_mark' => 70])->assertStatus(422);
    }

    public function test_examiners_and_course_lecturers_see_only_their_own_exams(): void
    {
        $this->setUpPlatform();
        $mine = $this->cbtExam(['title' => 'My paper']);
        $theirs = $this->cbtExam(['title' => 'Someone else paper']);
        $mySitting = $this->sitting($mine);
        $theirSitting = $this->sitting($theirs);
        $lecturer = $this->lecturer();

        $this->actingAs($lecturer)->get('/exam-board?show=all')->assertOk()->assertInertia(fn ($p) => $p->where('exams.data', []));
        $this->actingAs($lecturer)->get("/exam-board/{$mine->id}")->assertForbidden();

        $mine->examiners()->attach($lecturer->id, ['role' => 'examiner']);
        $this->actingAs($lecturer)->get('/exam-board?show=all')->assertInertia(fn ($p) => $p->has('exams.data', 1)->where('exams.data.0.title', 'My paper')->where('managesAll', false));
        $this->actingAs($lecturer)->get("/exam-board/{$mine->id}")->assertOk();
        $this->actingAs($lecturer)->get("/exam-board/{$theirs->id}")->assertForbidden();
        $this->actingAs($lecturer)->get("/exam-board/{$theirs->id}/export")->assertForbidden();

        // monitoring: their own sittings only
        $this->actingAs($lecturer)->get("/proctor/sessions/{$mySitting->id}")->assertOk();
        $this->actingAs($lecturer)->getJson("/proctor/sessions/{$mySitting->id}/live")->assertOk();
        $this->actingAs($lecturer)->get("/proctor/sessions/{$theirSitting->id}")->assertForbidden();
        $this->actingAs($lecturer)->get('/proctor')->assertOk()->assertInertia(fn ($p) => $p->has('sessions', 1));
        $this->actingAs($lecturer)->get('/incidents')->assertForbidden(); // the rest of the proctoring area stays closed

        // adding students to their own exam
        $student = User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/9001']);
        $this->actingAs($lecturer)->post("/exam-board/{$mine->id}/candidates", ['session_id' => $mySitting->id, 'identifiers' => 'VUG/9001'])->assertRedirect();
        $this->assertDatabaseHas('exam_candidates', ['exam_session_id' => $mySitting->id, 'user_id' => $student->id]);
        $this->actingAs($lecturer)->post("/exam-board/{$mine->id}/candidates", ['session_id' => $theirSitting->id, 'identifiers' => 'VUG/9001'])->assertNotFound();

        // the course lecturer of an exam's course is assigned too
        $course = \App\Models\Course::create(['code' => 'PHY101', 'title' => 'Physics']);
        $semester = \App\Models\Semester::query()->first() ?? \App\Models\Semester::create(['name' => 'First', 'academic_session_id' => \App\Models\AcademicSession::create(['name' => '2026/2027', 'starts_on' => now(), 'ends_on' => now()->addYear()])->id, 'starts_on' => now(), 'ends_on' => now()->addMonths(4)]);
        $offering = \App\Models\CourseOffering::create(['course_id' => $course->id, 'semester_id' => $semester->id]);
        DB::table('course_offering_staff')->insert(['course_offering_id' => $offering->id, 'user_id' => $lecturer->id, 'role' => 'lecturer', 'created_at' => now(), 'updated_at' => now()]);
        $theirs->update(['course_id' => $course->id, 'semester_id' => $semester->id]);
        $this->actingAs($lecturer)->get("/exam-board/{$theirs->id}")->assertOk();
    }

    public function test_proctors_never_receive_scores(): void
    {
        $this->setUpPlatform();
        $c = $this->candidate($this->sitting($this->cbtExam()));
        $t = $this->startAs($c)->json('token');
        $this->api($t, 'POST', 'submit')->assertOk();
        $json = $this->actingAs($this->officer)->getJson("/proctor/sessions/{$c->exam_session_id}/live?all=1")->assertOk()->getContent();
        $this->assertStringNotContainsString('score', str_replace('risk_score', '', $json));
        $this->assertStringNotContainsString('final', $json);
    }

    public function test_examiners_upload_questions_with_symbols_straight_into_their_exam(): void
    {
        $this->setUpPlatform();
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::fake('public');
        $exam = $this->cbtExam(['status' => 'draft'], []);
        $lecturer = $this->lecturer();
        $exam->examiners()->attach($lecturer->id, ['role' => 'examiner']);
        $file = UploadedFile::fake()->createWithContent('paper.docx', $this->docx());

        $res = $this->actingAs($lecturer)->post("/exam-board/{$exam->id}/questions", ['file' => $file, 'reader' => 'science']);
        $import = \App\Models\QuestionImport::firstOrFail();
        $res->assertRedirect("/exam-board/{$exam->id}/questions/{$import->id}");
        $this->assertSame('sci-docx', $import->format);
        $this->actingAs($lecturer)->get("/exam-board/{$exam->id}/questions/{$import->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('question-bank/import-preview')->has('questions', 2)->where('commitUrl', "/exam-board/{$exam->id}/questions/{$import->id}/commit"));
        $this->actingAs($lecturer)->post("/exam-board/{$exam->id}/questions/{$import->id}/commit")->assertRedirect("/exam-board/{$exam->id}");
        $this->assertSame(2, DB::table('exam_section_questions')->join('exam_sections', 'exam_sections.id', '=', 'exam_section_questions.exam_section_id')->where('exam_sections.exam_id', $exam->id)->count());

        $exam->update(['status' => 'scheduled']);
        $this->actingAs($lecturer)->post("/exam-board/{$exam->id}/questions", ['file' => UploadedFile::fake()->createWithContent('b.docx', $this->docx())])->assertStatus(422);
    }

    // ── the science & maths reader ───────────────────────────────────────────────

    public function test_word_equations_symbols_and_scripts_survive(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $this->docx());
        $html = (new WordScienceReader)->read($path, sys_get_temp_dir().'/vt-media-'.uniqid())['html'];

        $this->assertStringContainsString('1. Simplify <span class="math inline">\(\frac{x^{2}}{2}+\sqrt{y}\)</span>', $html);
        $this->assertStringContainsString('α', $html);          // Insert › Symbol
        $this->assertStringContainsString('π', $html);          // text typed in the Symbol font
        $this->assertStringContainsString('H<sub>2</sub>SO<sub>4</sub>', $html);
        $this->assertStringContainsString('10<sup>−6</sup>', $html);
        $this->assertStringContainsString('<p>A. ', $html);    // Word's lettered list is written out
        $this->assertStringContainsString('<p>B. ', $html);
        $this->assertStringContainsString('\sum_{i=1}^{n} i', $html);
        $this->assertStringContainsString('\left( a+b \right)', $html);
    }

    public function test_text_files_in_other_encodings_keep_their_symbols(): void
    {
        $this->assertSame('25 °C ± 2 µm²', DocumentConverter::toUtf8(mb_convert_encoding('25 °C ± 2 µm²', 'Windows-1252', 'UTF-8')));
        $this->assertSame('Δx → 0', DocumentConverter::toUtf8("\xFF\xFE".mb_convert_encoding('Δx → 0', 'UTF-16LE', 'UTF-8')));
        $this->assertSame('ok', DocumentConverter::toUtf8("\xEF\xBB\xBFok"));
    }

    /** A small Word file: an equation, a Symbol character, Symbol-font text, sub/superscripts and a lettered list. */
    private function docx(): string
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"';
        $r = fn ($t, $pr = '') => "<w:r>".($pr ? "<w:rPr>$pr</w:rPr>" : '')."<w:t xml:space=\"preserve\">$t</w:t></w:r>";
        $mr = fn ($t) => "<m:r><m:t>$t</m:t></m:r>";
        $eq = '<m:oMath><m:f><m:num><m:sSup><m:e>'.$mr('x').'</m:e><m:sup>'.$mr('2').'</m:sup></m:sSup></m:num><m:den>'.$mr('2').'</m:den></m:f>'.$mr('+')
            .'<m:rad><m:radPr><m:degHide m:val="1"/></m:radPr><m:deg/><m:e>'.$mr('y').'</m:e></m:rad></m:oMath>';
        $sum = '<m:oMath><m:nary><m:naryPr><m:chr m:val="∑"/></m:naryPr><m:sub>'.$mr('i=1').'</m:sub><m:sup>'.$mr('n').'</m:sup><m:e>'.$mr('i').'</m:e></m:nary>'
            .'<m:d><m:e>'.$mr('a+b').'</m:e></m:d></m:oMath>';
        $list = fn ($inner) => '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr>'.$inner.'</w:p>';
        $body = '<w:p>'.$r('1. Simplify ').$eq.'</w:p>'
            .$list($r('*Angle ').'<w:r><w:sym w:font="Symbol" w:char="F061"/></w:r>'.$r('p', '<w:rFonts w:ascii="Symbol" w:hAnsi="Symbol"/>'))
            .$list($r('H').$r('2', '<w:vertAlign w:val="subscript"/>').$r('SO').$r('4', '<w:vertAlign w:val="subscript"/>').$r(' and 10').$r('−6', '<w:vertAlign w:val="superscript"/>'))
            .'<w:p>'.$r('2. Evaluate ').$sum.'</w:p>'
            .$list($r('One')).$list($r('*Two'));
        $doc = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><w:document $w><w:body>$body</w:body></w:document>";
        $numbering = '<?xml version="1.0" encoding="UTF-8"?><w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:abstractNum w:abstractNumId="7"><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="upperLetter"/><w:lvlText w:val="%1."/></w:lvl></w:abstractNum>'
            .'<w:num w:numId="2"><w:abstractNumId w:val="7"/></w:num></w:numbering>';
        $file = tempnam(sys_get_temp_dir(), 'mk');
        $zip = new \ZipArchive;
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', $doc);
        $zip->addFromString('word/numbering.xml', $numbering);
        $zip->close();

        return file_get_contents($file);
    }
}
