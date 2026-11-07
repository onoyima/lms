<?php

namespace Tests\Feature\Lms;

use App\Models\AcademicSession;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\Enrolment;
use App\Models\Semester;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Lms\Participation;
use App\Services\Lms\Reminders;
use App\Services\Lms\Similarity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class LmsExtrasTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private User $lecturer;

    private CourseOffering $o;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        Storage::fake('local');
        $sem = Semester::create(['academic_session_id' => AcademicSession::create(['name' => '2026/2027', 'is_current' => true])->id, 'name' => 'First', 'is_current' => true]);
        $this->lecturer = User::factory()->create(['user_type' => 'staff']);
        $this->lecturer->assignRole('lecturer');
        $this->o = CourseOffering::create(['course_id' => Course::create(['code' => 'CHM 101', 'title' => 'Chemistry', 'credit_units' => 3, 'level' => 100])->id, 'semester_id' => $sem->id, 'status' => 'open']);
        $this->o->staff()->attach($this->lecturer->id, ['role' => 'coordinator']);
    }

    private function student(bool $enrol = true): User
    {
        $u = User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/'.fake()->unique()->numerify('#####')]);
        $u->assignRole('student');
        $enrol && Enrolment::create(['course_offering_id' => $this->o->id, 'user_id' => $u->id, 'status' => 'active']);

        return $u;
    }

    private function assignment(array $extra = []): CourseItem
    {
        $section = CourseSection::firstOrCreate(['course_offering_id' => $this->o->id, 'title' => 'Week 1']);

        return CourseItem::create(array_merge(['course_offering_id' => $this->o->id, 'course_section_id' => $section->id, 'type' => 'assignment', 'title' => 'Essay',
            'visible' => true, 'due_at' => now()->addDays(3), 'settings' => ['max_score' => 20, 'file_types' => ['docx', 'pdf', 'txt'], 'max_mb' => 100]], $extra));
    }

    public function test_similarity_flags_copied_work_but_not_original_work(): void
    {
        $item = $this->assignment();
        $essay = 'Photosynthesis converts light energy into chemical energy stored in glucose. Chlorophyll in the chloroplast absorbs red and blue light, '
            .'water is split to release oxygen, and carbon dioxide is fixed in the Calvin cycle using ATP and NADPH produced by the light reactions.';
        [$a, $b, $c] = [$this->student(), $this->student(), $this->student()];
        $this->actingAs($a)->post("/learn/items/{$item->id}/submit", ['text' => $essay])->assertSessionHasNoErrors();
        $this->actingAs($b)->post("/learn/items/{$item->id}/submit", ['text' => str_replace('glucose', 'sugar', $essay)])->assertSessionHasNoErrors();
        $this->actingAs($c)->post("/learn/items/{$item->id}/submit", ['text' => 'Respiration in mitochondria releases energy from food through glycolysis, the Krebs cycle and oxidative phosphorylation, producing carbon dioxide, water and many molecules of ATP for the cell to use in growth, movement and repair.'])->assertSessionHasNoErrors();

        $sim = app(Similarity::class);
        foreach (AssignmentSubmission::all() as $s) {
            $sim->check($s);
        }
        $this->assertGreaterThan(60, (float) AssignmentSubmission::where('user_id', $b->id)->value('similarity'));
        $this->assertLessThan(15, (float) AssignmentSubmission::where('user_id', $c->id)->value('similarity'));
        $this->actingAs($this->lecturer)->get("/learn/items/{$item->id}")->assertInertia(fn ($p) => $p->where('submissions.data.0.similarity', fn ($v) => $v > 60));
    }

    public function test_resumable_upload_survives_a_dropped_piece_and_attaches_to_the_submission(): void
    {
        $item = $this->assignment();
        $s = $this->student();
        $data = str_repeat('Veritas resumable upload. ', 40000); // ~1 MB
        $start = $this->actingAs($s)->postJson('/uploads', ['purpose' => "assignment:{$item->id}", 'filename' => 'report.txt', 'size' => strlen($data)])->assertCreated();
        $id = $start->json('id');

        $half = intdiv(strlen($data), 2);
        $this->call('PUT', "/uploads/$id?offset=0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($data, 0, $half))->assertOk()->assertJson(['received' => $half]);
        // the connection drops and the browser retries an old piece: the server says where to continue
        $this->call('PUT', "/uploads/$id?offset=0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($data, 0, 10))->assertStatus(409)->assertJson(['received' => $half]);
        $this->getJson("/uploads/$id")->assertJson(['received' => $half]);
        $this->call('PUT', "/uploads/$id?offset=$half", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], substr($data, $half))->assertOk()->assertJson(['complete' => true]);

        // someone else cannot use it, and the wrong file type is refused at the start
        $other = $this->student();
        $this->actingAs($other)->call('PUT', "/uploads/$id?offset=0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'x')->assertNotFound();
        $this->actingAs($other)->postJson('/uploads', ['purpose' => "assignment:{$item->id}", 'filename' => 'virus.exe', 'size' => 10])->assertStatus(422);

        $this->actingAs($s)->post("/learn/items/{$item->id}/submit", ['upload_id' => $id])->assertSessionHasNoErrors();
        $sub = AssignmentSubmission::where('user_id', $s->id)->first();
        $this->assertSame('report.txt', $sub->file_name);
        $this->assertSame(hash('sha256', $data), $sub->file_hash);
        $this->actingAs($s)->post("/learn/items/{$item->id}/submit", ['upload_id' => $id])->assertSessionHasErrors('file'); // used once
    }

    public function test_messaging_follows_the_institution_rules(): void
    {
        [$a, $b] = [$this->student(), $this->student()];
        $outsider = $this->student(false);

        $this->actingAs($a)->post('/messages', ['to' => [$this->lecturer->id], 'body' => 'Please explain question 3.'])->assertRedirect();
        $this->assertSame(1, $this->lecturer->notifications()->count());
        $this->actingAs($a)->post('/messages', ['to' => [$b->id], 'body' => 'hi'])->assertForbidden(); // classmates off by default
        $this->actingAs($a)->post('/messages', ['to' => [$outsider->id], 'body' => 'hi'])->assertForbidden();
        $this->actingAs($a)->getJson('/messages/recipients?q='.urlencode(substr($b->name, 0, 3)))->assertOk()->assertJsonMissing(['id' => $b->id]);

        $this->tenant->update(['settings' => ['policy' => ['student_messaging' => true]]]);
        tenant()?->refresh();
        $this->actingAs($a)->post('/messages', ['to' => [$b->id], 'body' => 'Study group?'])->assertRedirect();

        $conv = DB::table('conversations')->latest('id')->first();
        $this->actingAs($outsider)->post("/messages/{$conv->id}", ['body' => 'let me in'])->assertNotFound();
        $msg = DB::table('messages')->where('conversation_id', $conv->id)->first();
        $this->actingAs($b)->post("/messages/items/{$msg->id}/report", ['reason' => 'spam'])->assertRedirect();
        $this->assertDatabaseHas('message_reports', ['message_id' => $msg->id]);
    }

    public function test_support_tickets_reach_the_right_desk_and_staff_notes_stay_private(): void
    {
        $s = $this->student();
        $officer = User::factory()->create(['user_type' => 'staff']);
        $officer->assignRole('exam_officer');
        $this->actingAs($s)->post('/help/tickets', ['subject' => 'My result is missing', 'category' => 'results', 'body' => 'CHM 101 shows nothing', 'priority' => 'urgent'])->assertRedirect();
        $t = SupportTicket::first();
        $this->assertSame('normal', $t->priority); // students cannot self-mark urgent
        $this->assertSame(1, $officer->notifications()->count());

        $this->actingAs($this->officer)->post("/help/tickets/{$t->id}", ['body' => 'Check with HOD first', 'internal' => true])->assertRedirect();
        $this->actingAs($this->officer)->post("/help/tickets/{$t->id}", ['body' => 'Fixed — please check again', 'status' => 'resolved'])->assertRedirect();
        $this->actingAs($s)->get("/help/tickets/{$t->id}")->assertInertia(fn ($p) => $p->has('messages', 2)->where('messages.1.body', 'Fixed — please check again'));
        $this->actingAs($this->student())->get("/help/tickets/{$t->id}")->assertNotFound();
        $this->assertSame('resolved', $t->fresh()->status);

        $this->actingAs($this->officer)->post('/help/articles', ['title' => 'Reset your password', 'category' => 'Account', 'audience' => 'all', 'body_html' => '<p>Use <b>Forgot password</b>.</p><script>x</script>'])->assertRedirect();
        $this->actingAs($s)->get('/help?q=password')->assertInertia(fn ($p) => $p->where('articles.0.title', 'Reset your password'));
        $this->actingAs($s)->post('/help/articles', ['title' => 'x', 'category' => 'x', 'audience' => 'all', 'body_html' => 'x'])->assertForbidden();
    }

    public function test_calendar_feed_timetable_changes_and_search(): void
    {
        $s = $this->student();
        $item = $this->assignment(['due_at' => now()->startOfWeek()->addDays(2)->setTime(12, 0)]);
        CourseItem::create(['course_offering_id' => $this->o->id, 'course_section_id' => $item->course_section_id, 'type' => 'page', 'title' => 'Secret answers', 'visible' => false]);

        $this->actingAs($this->lecturer)->put("/learn/offerings/{$this->o->id}/schedule", ['slots' => [['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'kind' => 'lecture', 'venue' => 'LT1']], 'notify' => true])->assertRedirect();
        $this->assertSame(1, $s->notifications()->count());
        $this->actingAs($s)->put("/learn/offerings/{$this->o->id}/schedule", ['slots' => []])->assertForbidden();

        $this->actingAs($s)->get('/learn/calendar?week='.now()->startOfWeek()->toDateString())
            ->assertInertia(fn ($p) => $p->where('events', fn ($e) => collect($e)->pluck('kind')->contains('class') && collect($e)->pluck('kind')->contains('due')));
        $this->actingAs($s)->post('/learn/calendar/token')->assertRedirect();
        $token = $s->fresh()->calendar_token;
        auth()->guard('web')->logout();
        $ics = $this->get("/calendar/$token.ics")->assertOk()->assertHeader('content-type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('LOCATION:LT1', $ics);
        $this->get('/calendar/'.str_repeat('x', 48).'.ics')->assertNotFound();

        $this->actingAs($s)->get('/learn/search?q=Essay')->assertInertia(fn ($p) => $p->where('results.0.title', 'Essay'));
        $this->actingAs($s)->get('/learn/search?q=Secret')->assertInertia(fn ($p) => $p->has('results', 0));
        $this->actingAs($this->lecturer)->get('/learn/search?q=Secret')->assertInertia(fn ($p) => $p->has('results', 1));
    }

    public function test_reminders_go_once_and_only_to_those_who_have_not_handed_in(): void
    {
        $item = $this->assignment(['due_at' => now()->addHours(23)->addMinutes(30)]);
        [$done, $todo] = [$this->student(), $this->student()];
        $this->actingAs($done)->post("/learn/items/{$item->id}/submit", ['text' => 'my answer here'])->assertSessionHasNoErrors();

        $this->assertSame(1, app(Reminders::class)->run());
        $this->assertSame(0, app(Reminders::class)->run());
        $this->assertSame(1, $todo->notifications()->count());
        $this->assertSame(0, $done->notifications()->count());
    }

    public function test_participation_scores(): void
    {
        [$active, $quiet] = [$this->student(), $this->student()];
        $m = DB::table('class_meetings')->insertGetId(['tenant_id' => tenant_id(), 'course_offering_id' => $this->o->id, 'held_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attendance_records')->insert(['class_meeting_id' => $m, 'user_id' => $active->id, 'status' => 'present', 'created_at' => now(), 'updated_at' => now()]);
        $scores = app(Participation::class)->scores($this->o);
        $this->assertGreaterThan($scores[$quiet->id]['score'], $scores[$active->id]['score']);
        $this->assertSame(100, $scores[$active->id]['attendance']);
        $this->actingAs($this->lecturer)->get("/learn/courses/{$this->o->id}/gradebook")->assertInertia(fn ($p) => $p->has('participation'));
    }
}
