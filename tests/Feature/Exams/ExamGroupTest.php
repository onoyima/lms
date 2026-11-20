<?php

namespace Tests\Feature\Exams;

use App\Models\AcademicSession;
use App\Models\Attempt;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamGroup;
use App\Models\ExamLink;
use App\Models\ExamLinkEntry;
use App\Models\Programme;
use App\Models\Question;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One exam with several papers (objective, theory, practical, viva), set up from one place; and a
 * sitting's shareable link for students (sign in) or anyone (exam-only guest account).
 */
class ExamGroupTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private Course $course;

    private Semester $semester;

    /** @var list<User> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $this->semester = Semester::create(['academic_session_id' => $session->id, 'name' => 'First semester', 'is_current' => true, 'starts_on' => '2026-10-01']);
        $this->course = Course::create(['code' => 'NUR 401', 'title' => 'Nursing practice', 'credit_units' => 3, 'level' => 400]);
        $offering = CourseOffering::create(['course_id' => $this->course->id, 'semester_id' => $this->semester->id, 'status' => 'open']);
        $faculty = \App\Models\Faculty::create(['name' => 'Health Sciences', 'code' => 'HSC']);
        $dept = \App\Models\Department::create(['faculty_id' => $faculty->id, 'name' => 'Nursing', 'code' => 'NUR']);
        $programme = Programme::create(['code' => 'BNSC', 'name' => 'B.N.Sc. Nursing', 'department_id' => $dept->id]);
        foreach (range(1, 4) as $i) {
            $u = User::factory()->create(['user_type' => 'student', 'name' => "Student $i", 'identifier' => "VUG/NUR/$i"]);
            $u->assignRole('student');
            if ($i <= 3) {
                Enrolment::create(['course_offering_id' => $offering->id, 'user_id' => $u->id, 'status' => 'active']);
            }
            DB::table('student_profiles')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'programme_id' => $programme->id, 'level' => $i === 4 ? 300 : 400, 'created_at' => now(), 'updated_at' => now()]);
            $this->students[] = $u;
        }
        foreach (range(1, 8) as $i) {
            $q = Question::create(['course_id' => $this->course->id, 'type' => 'single_choice', 'stem_html' => "<p>Q$i</p>", 'mark' => 1, 'status' => 'approved', 'topic' => 'Care']);
            $q->options()->create(['label' => 'A', 'body_html' => 'yes', 'is_correct' => true, 'position' => 0]);
            $q->options()->create(['label' => 'B', 'body_html' => 'no', 'is_correct' => false, 'position' => 1]);
        }
    }

    private function as(?User $u)
    {
        $this->flushSession();
        auth()->guard('web')->logout();
        if ($u) {
            Cache::forget(\App\Http\Middleware\SingleStudentSession::key($u->id));
            $this->actingAs($u);
        }

        return $this;
    }

    private function nursing(array $papers = null)
    {
        return $this->actingAs($this->officer)->post('/exam-groups', [
            'title' => 'Nursing Professional Exam', 'code' => 'NUR 401', 'course_id' => $this->course->id, 'semester_id' => $this->semester->id,
            'papers' => $papers ?? [
                ['type' => 'cbt', 'duration' => 60, 'starts_at' => now()->subMinutes(5)->format('Y-m-d H:i'), 'mode' => 'remote', 'protection' => 'standard'],
                ['type' => 'essay', 'duration' => 120, 'starts_at' => now()->addDay()->format('Y-m-d H:i')],
                ['type' => 'practical', 'duration' => 90],
                ['type' => 'viva', 'duration' => 30, 'starts_at' => now()->addDays(3)->format('Y-m-d H:i')],
            ],
        ]);
    }

    public function test_one_exam_with_four_papers_and_what_each_still_needs(): void
    {
        $this->actingAs($this->officer)->get('/exams/new')->assertOk()->assertInertia(fn ($p) => $p->component('exams/new')->has('papers', 6));
        $this->get('/exams/quick')->assertRedirect('/exams/new');

        $res = $this->nursing();
        $group = ExamGroup::sole();
        $res->assertRedirect(route('exam-groups.show', $group));
        $this->assertSame(['cbt', 'essay', 'practical', 'viva'], $group->papers()->pluck('type')->all());
        $this->assertSame('Nursing Professional Exam · Objective', $group->papers()->first()->title);
        $this->assertSame(3, Exam::whereNotNull('exam_group_id')->has('sessions')->count()); // the practical has no date yet
        $this->assertSame(1, Exam::where('type', 'viva')->sole()->criteria()->count());
        $this->assertTrue((bool) Exam::where('type', 'cbt')->sole()->proctoring['webcam']);

        $papers = $this->get("/exam-groups/{$group->id}")->assertOk()->viewData('page')['props']['papers'];
        $objective = collect($papers)->firstWhere('type', 'cbt');
        $this->assertSame('paper', $objective['next']); // no questions yet
        $this->assertTrue(collect($objective['steps'])->firstWhere('key', 'examiners')['done']); // marked automatically
        $practical = collect($papers)->firstWhere('type', 'practical');
        $this->assertFalse(collect($practical['steps'])->firstWhere('key', 'sittings')['done']);

        // add another paper later
        $this->post("/exam-groups/{$group->id}/papers", ['type' => 'mixed', 'duration' => 90])->assertSessionHasNoErrors();
        $this->assertSame(5, $group->papers()->count());

        // renaming the exam renames its papers
        $this->put("/exam-groups/{$group->id}", ['title' => 'Nursing Final Exam'])->assertSessionHasNoErrors();
        $this->assertSame('Nursing Final Exam · Objective', Exam::where('type', 'cbt')->sole()->title);

        // the exams list shows the exam with its papers; a paper's page links back to it
        $groups = $this->get('/exams')->viewData('page')['props']['groups'];
        $this->assertSame('Nursing Final Exam', $groups[0]['title']);
        $this->get('/exams/'.Exam::where('type', 'cbt')->sole()->id)->assertInertia(fn ($p) => $p->where('group.title', 'Nursing Final Exam'));
    }

    public function test_students_from_the_class_list_a_programme_or_a_list(): void
    {
        $this->nursing();
        $group = ExamGroup::sole();
        $cbt = Exam::where('type', 'cbt')->sole();
        $essay = Exam::where('type', 'essay')->sole();
        $practical = Exam::where('type', 'practical')->sole();

        // class list into two papers at once
        $this->post("/exam-groups/{$group->id}/students", ['papers' => [$cbt->id, $essay->id], 'source' => 'course'])->assertSessionHasNoErrors();
        $this->assertSame(3, ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', $cbt->id))->count());
        $this->assertSame(3, ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', $essay->id))->count());

        // a programme and level from the student records (student 4 is in 300 level)
        $viva = Exam::where('type', 'viva')->sole();
        $this->post("/exam-groups/{$group->id}/students", ['papers' => [$viva->id], 'source' => 'programme', 'programme_id' => Programme::sole()->id, 'level' => 400])->assertSessionHasNoErrors();
        $this->assertSame(3, ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', $viva->id))->count());

        // a paper without a date cannot take students yet
        $this->post("/exam-groups/{$group->id}/students", ['papers' => [$practical->id], 'source' => 'list', 'identifiers' => 'VUG/NUR/4'])->assertSessionHasErrors('papers');

        // a pasted list, with one number that does not exist
        $this->post("/exam-groups/{$group->id}/students", ['papers' => [$cbt->id], 'source' => 'list', 'identifiers' => "VUG/NUR/4\nNOPE/1"])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 student places added') && str_contains($m, 'NOPE/1'));
        $this->assertSame(4, ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', $cbt->id))->count());
    }

    public function test_send_every_ready_paper_for_approval_then_students_sit_it(): void
    {
        $this->nursing();
        $group = ExamGroup::sole();
        $cbt = Exam::where('type', 'cbt')->sole();
        $cbt->sections()->first()->update(['blueprint' => [['count' => 5]]]);
        $cbt->update(['settings' => array_replace((array) $cbt->settings, ['require_check_in' => false, 'late_start' => 'any_time'])]);
        $this->post("/exam-groups/{$group->id}/students", ['papers' => [$cbt->id], 'source' => 'course']);

        // the objective, practical and viva have content; the theory paper has no questions and stays a draft
        $this->post("/exam-groups/{$group->id}/submit")->assertSessionHas('success', '3 papers sent for approval.');
        $this->assertSame('draft', Exam::where('type', 'essay')->sole()->status);
        $this->assertSame('submitted', $cbt->fresh()->status);

        // a second person approves; a student opens it from My exams and starts with their own five questions
        $other = User::factory()->create();
        $other->assignRole('institution_admin');
        $this->as($other)->post("/exams/{$cbt->id}/transition", ['action' => 'approve'])->assertSessionHasNoErrors();
        $cbt->sessions()->update(['status' => 'open']);
        $c = ExamCandidate::where('user_id', $this->students[0]->id)->whereHas('session', fn ($q) => $q->where('exam_id', $cbt->id))->sole();
        $token = $this->startWithChecks($this->students[0], $c->id)->json('token');
        $this->assertCount(5, $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/exam/attempt/paper')->json('paper.sections.0.questions'));
    }

    /** An approved, open objective paper with a shared link. */
    private function sharedSitting(string $audience = 'institution', ?string $code = null): array
    {
        $this->nursing([['type' => 'cbt', 'duration' => 20, 'starts_at' => now()->subMinutes(2)->format('Y-m-d H:i'), 'mode' => 'remote', 'protection' => 'light']]);
        $exam = Exam::sole();
        $exam->sections()->first()->update(['blueprint' => [['count' => 5]]]);
        $exam->update(['status' => 'scheduled', 'settings' => array_replace((array) $exam->settings, ['require_check_in' => false, 'late_start' => 'any_time'])]);
        $session = $exam->sessions()->sole();
        $session->update(['status' => 'open']);
        $this->actingAs($this->officer)->post("/sessions/{$session->id}/share")->assertRedirect(route('exams.ready', $session));
        $this->actingAs($this->officer)->put("/sessions/{$session->id}/link", array_filter(['audience' => $audience, 'code' => $code]))->assertSessionHasNoErrors();

        return [$session, ExamLink::sole()];
    }

    public function test_a_shared_link_lets_students_sign_in_and_start(): void
    {
        [$session, $link] = $this->sharedSitting('institution', 'NUR2026');
        $page = $this->actingAs($this->officer)->get("/sessions/{$session->id}/ready")->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString('/x/'.$link->token, $page['link']['url']);
        $this->assertStringContainsString('<svg', $page['link']['qr']);
        $this->assertSame('NUR2026', $page['link']['code']);
        $this->assertNotSame('NUR2026', $link->getAttributes()['code_hash']);

        $s = $this->students[0]; // not on this sitting's list: any student of the institution may take it (if eligible)
        $this->as(null)->post("/x/{$link->token}", ['code' => 'NUR2026'])->assertRedirect(route('login'));
        $this->as($s)->post("/x/{$link->token}", ['code' => 'wrong'])->assertSessionHasErrors('code');
        $this->as($s)->post("/x/{$link->token}", ['code' => 'NUR2026'])->assertRedirect();
        $c = ExamCandidate::where('user_id', $s->id)->sole();
        $this->startWithChecks($s, $c->id);

        // only students on the list, when the link says so
        $this->actingAs($this->officer)->put("/sessions/{$session->id}/link", ['audience' => 'roster']);
        $this->as($this->students[1])->post("/x/{$link->token}", ['code' => 'NUR2026'])->assertForbidden();

        // closing early: nobody new can start
        $this->actingAs($this->officer)->put("/sessions/{$session->id}/link", ['audience' => 'institution']);
        $this->as($this->officer)->post("/sessions/{$session->id}/close")->assertSessionHasNoErrors();
        $this->as($this->students[2])->post("/x/{$link->token}", ['code' => 'NUR2026'])->assertSessionHas('error');
    }

    public function test_anyone_with_the_link_gets_an_exam_only_account(): void
    {
        [$session, $link] = $this->sharedSitting('anyone');
        $this->as(null)->get("/x/{$link->token}")->assertOk()->assertInertia(fn ($p) => $p->component('exam-link')->where('audience', 'anyone')->where('ask', ['identifier']));
        $this->as(null)->post("/x/{$link->token}", ['name' => 'Ngozi Eze'])->assertSessionHasErrors('identifier');
        $res = $this->post("/x/{$link->token}", ['name' => 'Ngozi Eze', 'identifier' => 'JAMB-12345']);
        $entry = ExamLinkEntry::sole();
        $res->assertRedirect(route('candidate.room', $entry->exam_candidate_id));
        $guest = User::findOrFail($entry->user_id);
        $this->assertSame(ExamLink::GUEST_TYPE, $guest->user_type);
        $this->assertAuthenticatedAs($guest);

        // the guest can only reach their exam room
        $this->get('/dashboard')->assertRedirect(route('candidate.room', $entry->exam_candidate_id));
        $this->startWithChecks(null, $entry->exam_candidate_id);

        // someone typing the same number on another computer is stopped, until the organiser lets her move
        $this->as(null)->post("/x/{$link->token}", ['name' => 'Someone', 'identifier' => 'JAMB-12345'])->assertSessionHasErrors('identifier');
        $this->as($this->officer)->post("/sessions/{$session->id}/link-entries/{$entry->id}/release")->assertSessionHasNoErrors();
        $this->as(null)->post("/x/{$link->token}", ['name' => 'Ngozi Eze', 'identifier' => 'JAMB-12345'])->assertRedirect(route('candidate.room', $entry->exam_candidate_id));
        $this->assertSame(1, ExamLinkEntry::count());

        $people = $this->as($this->officer)->get("/sessions/{$session->id}/ready")->viewData('page')['props']['people'];
        $this->assertSame('Ngozi Eze', $people[0]['name']);
        $this->assertSame('in_progress', $people[0]['status']);

        // long after the sitting, not writing: the account stops working
        Attempt::query()->update(['status' => 'submitted', 'submitted_at' => now()]);
        $session->update(['ends_at' => now()->subHours(2), 'starts_at' => now()->subHours(3)]);
        $this->as($guest)->get('/my/exams/'.$entry->exam_candidate_id)->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_students_of_the_whole_category_sit_every_exam_including_ones_added_later(): void
    {
        $this->nursing();
        $group = ExamGroup::sole();
        $this->post("/exam-groups/{$group->id}/students", ['target' => 'category', 'source' => 'list', 'identifiers' => "VUG/NUR/1\nVUG/NUR/2"])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 students added to the category') && str_contains($m, 'Practical'));
        foreach (['cbt', 'essay', 'viva'] as $type) {
            $this->assertSame(2, ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', Exam::where('type', $type)->sole()->id))->count(), $type);
        }

        // the practical gets them as soon as it has a date; a new exam added later gets them too
        $practical = Exam::where('type', 'practical')->sole();
        $practical->sessions()->create(['mode' => 'hall', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2), 'status' => 'scheduled']);
        $this->assertSame(2, ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', $practical->id))->count());
        $this->post("/exam-groups/{$group->id}/papers", ['type' => 'cbt', 'title' => 'Objective resit quiz', 'category' => 'quiz', 'duration' => 20, 'starts_at' => now()->addDays(5)->format('Y-m-d H:i')])->assertSessionHasNoErrors();
        $quiz = Exam::where('title', 'like', '%Objective resit quiz')->sole();
        $this->assertSame('quiz', $quiz->category);
        $this->assertSame(2, ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', $quiz->id))->count());

        // one exam's own students stay on that exam only
        $viva = Exam::where('type', 'viva')->sole();
        $this->post("/exam-groups/{$group->id}/students", ['target' => 'exams', 'papers' => [$viva->id], 'source' => 'list', 'identifiers' => 'VUG/NUR/4'])->assertSessionHasNoErrors();
        $this->assertSame(3, ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', $viva->id))->count());
        $this->assertSame(2, ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', Exam::where('type', 'essay')->sole()->id))->count());

        $page = $this->get("/exam-groups/{$group->id}")->viewData('page')['props'];
        $this->assertSame(2, $page['students']['count']);
        $this->assertContains('Objective resit quiz', collect($page['papers'])->pluck('label')->all());

        // taken off the category: later exams no longer include them
        $this->delete("/exam-groups/{$group->id}/students/{$this->students[0]->id}")->assertSessionHasNoErrors();
        $this->post("/exam-groups/{$group->id}/papers", ['type' => 'essay', 'duration' => 60, 'starts_at' => now()->addDays(6)->format('Y-m-d H:i')]);
        $this->assertSame(1, ExamCandidate::whereHas('session', fn ($q) => $q->where('exam_id', Exam::latest('id')->first()->id))->count());
    }

    public function test_combined_results_for_the_category(): void
    {
        $this->nursing();
        $group = ExamGroup::sole();
        $cbt = Exam::where('type', 'cbt')->sole();
        $viva = Exam::where('type', 'viva')->sole();
        $this->post("/exam-groups/{$group->id}/students", ['target' => 'category', 'source' => 'list', 'identifiers' => "VUG/NUR/1\nVUG/NUR/2"]);
        $cbt->sessions()->update(['ends_at' => now()->subMinute()]); // the objective is over
        \App\Models\Result::create(['exam_id' => $cbt->id, 'user_id' => $this->students[0]->id, 'raw_score' => 40, 'max_score' => 50, 'final_score' => 80, 'status' => 'provisional']);
        \App\Models\Result::create(['exam_id' => $viva->id, 'user_id' => $this->students[0]->id, 'raw_score' => 15, 'max_score' => 20, 'final_score' => 75, 'status' => 'provisional']);

        $csv = $this->get("/exam-groups/{$group->id}/report.csv")->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", trim(ltrim($csv, "\u{FEFF}"))))));
        $head = $lines[0];
        $this->assertSame(['Matric number', 'Name'], array_slice($head, 0, 2));
        $this->assertSame(['Total', 'Out of', '%', 'Exams sat'], array_slice($head, -4));
        $this->assertStringStartsWith('Objective', $head[2]);
        $first = collect($lines)->first(fn ($l) => $l[0] === 'VUG/NUR/1');
        $second = collect($lines)->first(fn ($l) => $l[0] === 'VUG/NUR/2');
        $this->assertSame(['80', '155', '200', '77.5', '2/4'], [$first[2], $first[6], $first[7], $first[8], $first[9]]);
        $this->assertSame('ABS', $second[2]); // assigned, the objective is over, did not sit
        $this->get("/exam-groups/{$group->id}/report.xlsx")->assertOk()->assertDownload();
        $this->get("/exam-groups/{$group->id}/report.pdf")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'exam_group.report_exported']);
    }

    /** Starting asks for the pre-exam checks the paper's protection level chose; pass them all. */
    private function startWithChecks($user, int $candidateId): \Illuminate\Testing\TestResponse
    {
        $req = $user ? $this->as($user) : $this;
        $first = $req->postJson("/my/exams/{$candidateId}/start", ['consent' => true]);
        if ($first->status() === 422 && $first->json('reason') === 'pre_checks') {
            $first = $req->postJson("/my/exams/{$candidateId}/start", ['consent' => true, 'checks' => array_fill_keys($first->json('missing'), true)]);
        }

        return $first->assertOk();
    }
}
