<?php

namespace Tests\Feature\Lms;

use App\Models\AcademicSession;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseGrade;
use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\Enrolment;
use App\Models\Exam;
use App\Models\Programme;
use App\Models\RegistrationWindow;
use App\Models\Semester;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class LmsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private Semester $semester;

    private User $lecturer;

    private User $hod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        Storage::fake('local');
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $this->semester = Semester::create(['academic_session_id' => $session->id, 'name' => 'First semester', 'is_current' => true, 'starts_on' => '2026-10-01']);
        $this->lecturer = User::factory()->create(['user_type' => 'staff']);
        $this->lecturer->assignRole('lecturer');
        $this->hod = User::factory()->create(['user_type' => 'staff']);
        $this->hod->assignRole('hod');
    }

    private function offering(string $code = 'CHM 101', int $units = 3, ?int $capacity = null): CourseOffering
    {
        $course = Course::create(['code' => $code, 'title' => "Course $code", 'credit_units' => $units, 'level' => 100]);
        $o = CourseOffering::create(['course_id' => $course->id, 'semester_id' => $this->semester->id, 'capacity' => $capacity, 'status' => 'open']);
        $o->staff()->attach($this->lecturer->id, ['role' => 'coordinator']);

        return $o;
    }

    private function student(array $profile = []): User
    {
        $u = User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/'.fake()->unique()->numerify('#####')]);
        $u->assignRole('student');
        StudentProfile::create(array_merge(['user_id' => $u->id, 'level' => 100, 'academic_status' => 'active'], $profile));

        return $u;
    }

    private function enrol(CourseOffering $o, User $u): void
    {
        Enrolment::create(['course_offering_id' => $o->id, 'user_id' => $u->id, 'status' => 'active']);
    }

    private function window(array $o = []): void
    {
        RegistrationWindow::create(array_merge(['semester_id' => $this->semester->id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addWeek(), 'min_units' => 3, 'max_units' => 6], $o));
    }

    // ── Registration ─────────────────────────────────────────────────────

    public function test_registration_enforces_window_units_prerequisites_and_capacity(): void
    {
        $chm = $this->offering('CHM 101', 3);
        $phy = $this->offering('PHY 101', 3);
        $mth = $this->offering('MTH 101', 3);
        $adv = $this->offering('CHM 201', 3, capacity: 1);
        DB::table('course_prerequisites')->insert(['course_id' => $adv->course_id, 'prerequisite_id' => $chm->course_id]);
        $s = $this->student();

        // closed
        $this->actingAs($s)->post('/learn/registration', ['courses' => [$chm->id]])->assertSessionHasErrors('courses');
        $this->window();
        // too many units
        $this->post('/learn/registration', ['courses' => [$chm->id, $phy->id, $mth->id]])->assertSessionHasErrors('courses');
        // prerequisite not passed
        $this->post('/learn/registration', ['courses' => [$adv->id]])->assertSessionHasErrors('courses');
        // valid
        $this->post('/learn/registration', ['courses' => [$chm->id, $phy->id]])->assertSessionHasNoErrors();
        $this->assertSame(2, Enrolment::where('user_id', $s->id)->where('status', 'active')->count());
        // drop PHY, add MTH
        $this->post('/learn/registration', ['courses' => [$chm->id, $mth->id]])->assertSessionHasNoErrors();
        $this->assertSame('dropped', Enrolment::where('user_id', $s->id)->where('course_offering_id', $phy->id)->value('status'));

        // passed CHM 101 → may take CHM 201; capacity 1 stops the second student
        CourseGrade::create(['course_offering_id' => $chm->id, 'user_id' => $s->id, 'total' => 70, 'grade' => 'A', 'status' => 'released']);
        $this->post('/learn/registration', ['courses' => [$adv->id, $mth->id]])->assertSessionHasNoErrors();
        $other = $this->student();
        CourseGrade::create(['course_offering_id' => $chm->id, 'user_id' => $other->id, 'total' => 70, 'grade' => 'A', 'status' => 'released']);
        $this->actingAs($other)->post('/learn/registration', ['courses' => [$adv->id, $mth->id]])->assertSessionHasErrors('courses');
    }

    public function test_suspended_students_cannot_register_and_core_courses_are_required(): void
    {
        $this->window();
        $prog = Programme::create(['department_id' => \App\Models\Department::create(['faculty_id' => \App\Models\Faculty::create(['name' => 'Science', 'code' => 'SCI'])->id, 'name' => 'Chemistry', 'code' => 'CHM'])->id, 'name' => 'B.Sc Chemistry', 'code' => 'BSC-CHM']);
        $core = $this->offering('CHM 101', 3);
        $elec = $this->offering('GST 101', 3);
        DB::table('programme_courses')->insert([['tenant_id' => $this->tenant->id, 'programme_id' => $prog->id, 'course_id' => $core->course_id, 'level' => 100, 'category' => 'core'],
            ['tenant_id' => $this->tenant->id, 'programme_id' => $prog->id, 'course_id' => $elec->course_id, 'level' => 100, 'category' => 'elective']]);

        $s = $this->student(['programme_id' => $prog->id]);
        $this->actingAs($s)->post('/learn/registration', ['courses' => [$elec->id]])->assertSessionHasErrors('courses'); // core missing
        $this->post('/learn/registration', ['courses' => [$core->id]])->assertSessionHasNoErrors();

        $suspended = $this->student(['programme_id' => $prog->id, 'academic_status' => 'suspended']);
        $this->actingAs($suspended)->post('/learn/registration', ['courses' => [$core->id]])->assertSessionHasErrors('courses');
    }

    // ── Content release and access ───────────────────────────────────────

    public function test_students_only_see_released_items_and_must_complete_prerequisites(): void
    {
        $o = $this->offering();
        $s = $this->student();
        $outsider = $this->student();
        $this->enrol($o, $s);
        $section = CourseSection::create(['course_offering_id' => $o->id, 'title' => 'Week 1']);

        $this->actingAs($this->lecturer)->post("/learn/courses/{$o->id}/items", ['course_section_id' => $section->id, 'type' => 'page', 'title' => 'Intro', 'body_html' => '<p>Hello \\(x^2\\)</p><script>x</script>'])->assertRedirect();
        $intro = CourseItem::where('title', 'Intro')->first();
        $this->assertStringNotContainsString('<script', $intro->body_html);
        $this->post("/learn/courses/{$o->id}/items", ['course_section_id' => $section->id, 'type' => 'file', 'title' => 'Notes', 'prerequisite_item_id' => $intro->id,
            'file' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')]);
        $notes = CourseItem::where('title', 'Notes')->first();
        $this->post("/learn/courses/{$o->id}/items", ['course_section_id' => $section->id, 'type' => 'page', 'title' => 'Later', 'release_at' => now()->addDays(3)->toDateTimeString()]);
        $later = CourseItem::where('title', 'Later')->first();

        $this->actingAs($outsider)->get("/learn/courses/{$o->id}")->assertForbidden();
        $this->actingAs($outsider)->get("/learn/items/{$notes->id}/file")->assertForbidden();

        $this->actingAs($s)->get("/learn/items/{$later->id}")->assertForbidden();
        $this->get("/learn/items/{$notes->id}/file")->assertForbidden();   // prerequisite not done
        $this->get("/learn/items/{$intro->id}")->assertOk();                // viewing a page completes it
        $this->get("/learn/items/{$notes->id}/file")->assertOk();
        $this->assertSame(100, $this->get("/learn/courses/{$o->id}")->assertOk()->viewData('page')['props']['progress']['percent']); // unreleased items do not count

        // Editing keeps a revision
        $this->actingAs($this->lecturer)->post("/learn/items/{$intro->id}", ['title' => 'Introduction'])->assertRedirect();
        $this->assertDatabaseHas('course_item_revisions', ['course_item_id' => $intro->id, 'version' => 1]);
    }

    // ── Assignments ──────────────────────────────────────────────────────

    public function test_assignment_late_penalty_extension_cutoff_and_release(): void
    {
        $o = $this->offering();
        $section = CourseSection::create(['course_offering_id' => $o->id, 'title' => 'Week 1']);
        $item = CourseItem::create(['course_offering_id' => $o->id, 'course_section_id' => $section->id, 'type' => 'assignment', 'title' => 'Lab report',
            'due_at' => now()->subDays(2)->addMinutes(5), 'cutoff_at' => now()->addDay(), 'settings' => ['max_score' => 20, 'late_penalty_pct' => 10, 'file_types' => ['pdf']]]);
        [$late, $extended, $wrongType] = [$this->student(), $this->student(), $this->student()];
        foreach ([$late, $extended, $wrongType] as $u) {
            $this->enrol($o, $u);
        }

        $this->actingAs($wrongType)->post("/learn/items/{$item->id}/submit", ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');

        $this->actingAs($this->lecturer)->post("/learn/items/{$item->id}/extensions", ['identifier' => $extended->identifier, 'due_at' => now()->addDays(2)->toDateTimeString(), 'reason' => 'Medical'])->assertSessionHasNoErrors();

        $this->actingAs($late)->post("/learn/items/{$item->id}/submit", ['file' => UploadedFile::fake()->create('report.pdf', 50, 'application/pdf')])->assertSessionHasNoErrors();
        $this->actingAs($extended)->post("/learn/items/{$item->id}/submit", ['text' => '<p>My report</p>'])->assertSessionHasNoErrors();

        $lateSub = AssignmentSubmission::where('user_id', $late->id)->first();
        $extSub = AssignmentSubmission::where('user_id', $extended->id)->first();
        $this->assertTrue($lateSub->is_late);
        $this->assertFalse($extSub->is_late);
        $this->assertNotNull($lateSub->file_hash);

        // Students cannot download each other's work
        $this->actingAs($extended)->get("/learn/submissions/{$lateSub->id}/file")->assertForbidden();

        $this->actingAs($this->lecturer)->post("/learn/submissions/{$lateSub->id}/mark", ['score' => 15, 'feedback_html' => '<p>Good</p>'])->assertSessionHasNoErrors();
        $this->post("/learn/submissions/{$extSub->id}/mark", ['score' => 15])->assertSessionHasNoErrors();
        $this->assertEquals(12.0, (float) $lateSub->fresh()->score);   // 2 days late × 10 % = 20 % off
        $this->assertEquals(15.0, (float) $extSub->fresh()->score);

        // Marks hidden until released
        $props = $this->actingAs($late)->get("/learn/items/{$item->id}")->viewData('page')['props'];
        $this->assertNull($props['submissions'][0]['score']);
        $this->actingAs($this->lecturer)->post("/learn/items/{$item->id}/release")->assertSessionHasNoErrors();
        $props = $this->actingAs($late)->get("/learn/items/{$item->id}")->viewData('page')['props'];
        $this->assertEquals(12.0, (float) $props['submissions'][0]['score']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $late->id]);

        // After the cut-off, no more submissions (except the student with an extension)
        $this->travel(2)->days();
        $this->actingAs($wrongType)->post("/learn/items/{$item->id}/submit", ['text' => 'too late'])->assertSessionHasErrors('submission');
    }

    public function test_group_assignment_mark_goes_to_every_member(): void
    {
        $o = $this->offering();
        $section = CourseSection::create(['course_offering_id' => $o->id, 'title' => 'W']);
        $item = CourseItem::create(['course_offering_id' => $o->id, 'course_section_id' => $section->id, 'type' => 'assignment', 'title' => 'Group project',
            'due_at' => now()->addWeek(), 'settings' => ['max_score' => 10, 'group' => true]]);
        $a = $this->student();
        $b = $this->student();
        $this->enrol($o, $a);
        $this->enrol($o, $b);
        $this->actingAs($this->lecturer)->post("/learn/items/{$item->id}/groups", ['size' => 2])->assertSessionHasNoErrors();

        $this->actingAs($a)->post("/learn/items/{$item->id}/submit", ['text' => 'Our work'])->assertSessionHasNoErrors();
        $sub = AssignmentSubmission::first();
        $this->actingAs($this->lecturer)->post("/learn/submissions/{$sub->id}/mark", ['score' => 8]);
        $this->post("/learn/items/{$item->id}/release");

        app(\App\Services\Lms\GradebookService::class)->syncItem($item);
        $gi = \App\Models\GradeItem::where('source', 'item')->where('source_id', $item->id)->first();
        $this->assertEquals(8.0, (float) DB::table('grade_entries')->where('grade_item_id', $gi->id)->where('user_id', $b->id)->value('score'));
    }

    // ── Gradebook ────────────────────────────────────────────────────────

    public function test_gradebook_weights_ca_and_exam_and_needs_hod_approval_before_release(): void
    {
        $o = $this->offering();
        $s = $this->student();
        $this->enrol($o, $s);

        // CA: one manual test 15/20 = 75 %; Exam: central exam result 60/100
        $gb = app(\App\Services\Lms\GradebookService::class);
        $gb->ensureDefaults($o);
        $ca = $o->gradeCategories()->first();
        $this->actingAs($this->lecturer)->post("/learn/courses/{$o->id}/gradebook/items", ['title' => 'Test 1', 'max_score' => 20, 'grade_category_id' => $ca->id])->assertSessionHasNoErrors();
        $test = \App\Models\GradeItem::where('title', 'Test 1')->first();
        $this->post("/learn/grade-items/{$test->id}/entry", ['user_id' => $s->id, 'score' => 15])->assertSessionHasNoErrors();
        $this->post("/learn/grade-items/{$test->id}/entry", ['user_id' => $s->id, 'score' => 25])->assertSessionHasErrors('score');

        $exam = Exam::create(['title' => 'CHM 101 Exam', 'type' => 'cbt', 'status' => 'closed', 'total_mark' => 100, 'course_id' => $o->course_id, 'semester_id' => $o->semester_id, 'created_by' => $this->officer->id]);
        \App\Models\Result::create(['exam_id' => $exam->id, 'user_id' => $s->id, 'raw_score' => 60, 'max_score' => 100, 'final_score' => 60, 'status' => 'published']);

        $this->get("/learn/courses/{$o->id}/gradebook")->assertOk();
        $grade = CourseGrade::where('user_id', $s->id)->first();
        $this->assertEquals(64.5, (float) $grade->total); // 0.3 × 75 + 0.7 × 60

        // Weights must add to 100
        $this->put("/learn/courses/{$o->id}/gradebook/categories", ['categories' => [['name' => 'CA', 'weight' => 40], ['name' => 'Exam', 'weight' => 70]]])->assertSessionHasErrors('categories');

        // Lecturer cannot approve their own course; cannot release before approval
        $this->post("/learn/courses/{$o->id}/gradebook/transition", ['action' => 'approve'])->assertForbidden();
        $this->post("/learn/courses/{$o->id}/gradebook/transition", ['action' => 'release'])->assertSessionHasErrors('grades');
        $this->actingAs($this->hod)->post("/learn/courses/{$o->id}/gradebook/transition", ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->lecturer)->post("/learn/courses/{$o->id}/gradebook/transition", ['action' => 'release'])->assertSessionHasNoErrors();
        $this->assertSame('released', $grade->fresh()->status);

        // Released results are locked
        $this->post("/learn/grade-items/{$test->id}/entry", ['user_id' => $s->id, 'score' => 20])->assertSessionHasErrors('score');
        $this->actingAs($s)->get('/learn/grades')->assertOk()->assertSee('CHM 101');

        // CSV export neutralises formula injection
        $s->update(['name' => '=HYPERLINK("http://x")']);
        $csv = $this->actingAs($this->lecturer)->get("/learn/courses/{$o->id}/gradebook/export")->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    // ── Quizzes on the exam engine ───────────────────────────────────────

    public function test_quiz_uses_the_exam_engine_and_feeds_the_gradebook(): void
    {
        $o = $this->offering();
        $s = $this->student();
        $this->enrol($o, $s);
        $section = CourseSection::create(['course_offering_id' => $o->id, 'title' => 'W1']);
        $this->actingAs($this->lecturer)->post("/learn/courses/{$o->id}/items", ['course_section_id' => $section->id, 'type' => 'quiz', 'title' => 'Quiz 1',
            'settings' => ['duration' => 10, 'max_score' => 10]])->assertRedirect();
        $item = CourseItem::where('type', 'quiz')->first();
        $exam = Exam::find($item->exam_id);
        $this->assertSame('quiz', $exam->category);

        // Not published yet
        $this->actingAs($s)->post("/learn/items/{$item->id}/quiz/start")->assertSessionHasErrors('quiz');
        // Publish needs questions
        $this->actingAs($this->lecturer)->post("/learn/items/{$item->id}/quiz/publish")->assertSessionHasErrors('quiz');
        $q = $this->questions()[0];
        $exam->sections()->first()->questions()->attach($q->id, ['position' => 0]);
        $this->post("/learn/items/{$item->id}/quiz/publish")->assertSessionHasNoErrors();

        $room = $this->actingAs($s)->post("/learn/items/{$item->id}/quiz/start")->assertRedirect()->headers->get('Location');
        $candidateId = (int) basename($room);
        $token = $this->postJson("/my/exams/$candidateId/start", ['consent' => true])->assertOk()->json('token');
        $paper = $this->api($token, 'GET', 'paper')->json('paper');
        $qid = $paper['sections'][0]['questions'][0]['id'];
        $correct = $q->options()->where('is_correct', true)->value('id');
        $this->api($token, 'POST', 'submit', ['answers' => [['question_id' => $qid, 'seq' => 1, 'response' => ['options' => [$correct]]]]])->assertOk();

        app(\App\Services\Lms\GradebookService::class)->syncItem($item);
        $gi = \App\Models\GradeItem::where('source', 'item')->where('source_id', $item->id)->first();
        $this->assertEquals(10.0, (float) DB::table('grade_entries')->where('grade_item_id', $gi->id)->where('user_id', $s->id)->value('score'));
    }

    // ── Forums, announcements, attendance ────────────────────────────────

    public function test_forum_announcements_and_attendance(): void
    {
        $o = $this->offering();
        $s = $this->student();
        $outsider = $this->student();
        $this->enrol($o, $s);
        $section = CourseSection::create(['course_offering_id' => $o->id, 'title' => 'W1']);
        $forum = CourseItem::create(['course_offering_id' => $o->id, 'course_section_id' => $section->id, 'type' => 'forum', 'title' => 'Q&A']);

        $this->actingAs($s)->post("/learn/items/{$forum->id}/threads", ['title' => 'Question about moles', 'body_html' => '<p>What is \\(n = m/M\\)?</p>'])->assertRedirect();
        $thread = \App\Models\ForumThread::first();
        $this->actingAs($outsider)->post("/learn/threads/{$thread->id}/posts", ['body_html' => 'spam'])->assertForbidden();
        $this->actingAs($this->lecturer)->post("/learn/threads/{$thread->id}/posts", ['body_html' => '<p>Amount = mass / molar mass</p>'])->assertRedirect();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $s->id]);
        $this->post("/learn/threads/{$thread->id}/moderate", ['locked' => true]);
        $this->actingAs($s)->post("/learn/threads/{$thread->id}/posts", ['body_html' => 'more'])->assertForbidden();

        // Students cannot announce; lecturer can post to own course only
        $this->actingAs($s)->post('/learn/announcements', ['scope' => 'offering', 'scope_id' => $o->id, 'title' => 'x', 'body_html' => 'y'])->assertForbidden();
        $this->actingAs($this->lecturer)->post('/learn/announcements', ['scope' => 'institution', 'title' => 'x', 'body_html' => 'y'])->assertForbidden();
        $this->post('/learn/announcements', ['scope' => 'offering', 'scope_id' => $o->id, 'title' => 'Test on Friday', 'body_html' => '<p>Bring a calculator</p>'])->assertSessionHasNoErrors();
        $this->actingAs($s)->get('/learn/announcements')->assertOk()->assertSee('Test on Friday');

        // Attendance by code
        $this->actingAs($this->lecturer)->post("/learn/courses/{$o->id}/meetings", ['held_at' => now()->toDateTimeString(), 'topic' => 'Lecture 1', 'code_minutes' => 10]);
        $code = \App\Models\ClassMeeting::first()->code;
        $this->actingAs($outsider)->post('/learn/attendance/check-in', ['code' => $code])->assertStatus(422);
        $this->actingAs($s)->post('/learn/attendance/check-in', ['code' => strtolower($code)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attendance_records', ['user_id' => $s->id, 'status' => 'present', 'source' => 'code']);
    }

    public function test_lms_is_unavailable_to_institutions_without_the_module(): void
    {
        $this->tenant->update(['plan_id' => \App\Models\Plan::where('key', 'exams_only')->value('id')]);
        $this->actingAs($this->lecturer)->get('/learn/courses')->assertForbidden();
    }
}
