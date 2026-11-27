<?php

namespace Tests\Feature\Lms;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\Enrolment;
use App\Models\Semester;
use App\Models\TopicRequest;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Lms\CourseContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/**
 * Course materials of every kind shown in the page, what students can do with them, and what they
 * can say about a course: reviews, polls and requests for topics not covered.
 */
class CourseMaterialsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private CourseOffering $offering;

    private CourseSection $week;

    private User $lecturer;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        Storage::fake('local');
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $semester = Semester::create(['academic_session_id' => $session->id, 'name' => 'First semester', 'is_current' => true, 'starts_on' => '2026-10-01']);
        $course = Course::create(['code' => 'CHM 101', 'title' => 'General Chemistry', 'credit_units' => 3, 'level' => 100]);
        $this->offering = CourseOffering::create(['course_id' => $course->id, 'semester_id' => $semester->id, 'status' => 'open']);
        $this->lecturer = User::factory()->create(['user_type' => 'staff']);
        $this->lecturer->assignRole('lecturer');
        $this->offering->staff()->attach($this->lecturer->id, ['role' => 'coordinator']);
        $this->student = $this->learner();
        $this->week = CourseSection::create(['course_offering_id' => $this->offering->id, 'title' => 'Week 3', 'position' => 0]);
    }

    private function learner(): User
    {
        $u = User::factory()->create(['user_type' => 'student']);
        $u->assignRole('student');
        Enrolment::create(['course_offering_id' => $this->offering->id, 'user_id' => $u->id, 'status' => 'active']);

        return $u;
    }

    private function material(string $sample, string $type = 'file'): CourseItem
    {
        $path = database_path('seeders/samples/lms/'.$sample);

        return app(CourseContentService::class)->saveItem($this->offering, ['course_section_id' => $this->week->id, 'type' => $type, 'title' => $sample, 'requires_completion' => true],
            new UploadedFile($path, $sample, null, null, true), $this->lecturer);
    }

    public function test_word_slides_and_sheets_are_shown_in_the_page_with_their_pictures(): void
    {
        $word = $this->material('lecture-2-gas-laws.docx');
        $r = $this->actingAs($this->student)->getJson("/learn/items/{$word->id}/preview")->assertOk()->assertJsonPath('kind', 'word');
        $html = $r->json('html');
        $this->assertStringContainsString('<h1>Lecture 2: The gas laws</h1>', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('class="math display"', $html); // the equation is shown as maths
        $this->assertMatchesRegularExpression('#src="/learn/items/'.$word->id.'/preview/media/(img\d+\.png)"#', $html);
        preg_match('#/preview/media/([^"]+)"#', $html, $m);
        $this->actingAs($this->student)->get("/learn/items/{$word->id}/preview/media/{$m[1]}")->assertOk()->assertHeader('Content-Type', 'image/png');

        $slides = $this->material('states-of-matter-slides.pptx');
        $r = $this->actingAs($this->student)->getJson("/learn/items/{$slides->id}/preview")->assertOk()->assertJsonPath('kind', 'slides')->assertJsonPath('slides', 5);
        $this->assertStringContainsString('Particles move freely and fast', $r->json('html'));
        $this->assertStringContainsString('/preview/media/s5-', $r->json('html')); // the picture on the last slide

        $sheet = $this->material('molar-masses.xlsx');
        $this->assertStringContainsString('<td>Carbon</td>', $this->actingAs($this->student)->getJson("/learn/items/{$sheet->id}/preview")->json('html'));

        // someone not on the course sees none of it
        $this->actingAs(User::factory()->create())->getJson("/learn/items/{$word->id}/preview")->assertForbidden();
        $this->actingAs(User::factory()->create())->get("/learn/items/{$word->id}/preview/media/{$m[1]}")->assertForbidden();
    }

    public function test_videos_stream_in_pieces_and_count_as_done_when_watched_not_when_loaded(): void
    {
        $video = $this->material('titration-demo.mp4', 'video');
        $this->actingAs($this->student)->get("/learn/items/{$video->id}/file", ['Range' => 'bytes=0-1023'])
            ->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-1023/'.filesize(database_path('seeders/samples/lms/titration-demo.mp4')));
        $this->assertDatabaseMissing('item_completions', ['course_item_id' => $video->id]); // loading the player is not watching

        $this->actingAs($this->student)->post("/learn/items/{$video->id}/complete")->assertRedirect();
        $this->assertDatabaseHas('item_completions', ['course_item_id' => $video->id, 'user_id' => $this->student->id]);
        $this->actingAs($this->student)->delete("/learn/items/{$video->id}/complete")->assertRedirect();
        $this->assertDatabaseMissing('item_completions', ['course_item_id' => $video->id]);

        // a file that is downloaded counts once downloaded; HTML is never shown in the page
        $pdf = $this->material('lecture-2-handout.pdf');
        $this->actingAs($this->student)->get("/learn/items/{$pdf->id}/file")->assertOk()->assertHeader('Content-Disposition', 'inline; filename=lecture-2-handout.pdf');
        $this->actingAs($this->student)->get("/learn/items/{$pdf->id}/file?download=1")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=lecture-2-handout.pdf');
        $this->assertDatabaseHas('item_completions', ['course_item_id' => $pdf->id]);
    }

    public function test_item_page_gives_next_and_previous_notes_and_bookmarks(): void
    {
        $a = $this->material('carbon-atom-diagram.png');
        $b = $this->material('lecture-audio-sample.mp3');
        $page = $this->actingAs($this->student)->get("/learn/items/{$a->id}")->assertOk();
        $this->assertSame($b->id, $page->viewData('page')['props']['nav']['next']['id']);
        $this->assertNull($page->viewData('page')['props']['nav']['prev']);

        $this->actingAs($this->student)->put("/learn/items/{$a->id}/note", ['body' => 'Six protons', 'bookmarked' => true])->assertRedirect();
        $props = $this->actingAs($this->student)->get("/learn/items/{$a->id}")->viewData('page')['props'];
        $this->assertSame('Six protons', $props['note']['body']);
        $this->assertTrue($props['note']['bookmarked']);
        $course = $this->actingAs($this->student)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props'];
        $this->assertTrue(collect($course['sections'][0]['items'])->firstWhere('id', $a->id)['bookmarked']);

        // notes are private
        $other = $this->learner();
        $this->assertNull($this->actingAs($other)->get("/learn/items/{$a->id}")->viewData('page')['props']['note']);
    }

    public function test_pictures_in_pages_are_private_to_the_course(): void
    {
        $url = $this->actingAs($this->lecturer)->post("/learn/courses/{$this->offering->id}/media", ['file' => UploadedFile::fake()->image('diagram.png', 400, 300)])->assertOk()->json('url');
        $this->actingAs($this->student)->get($url)->assertOk();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($this->student)->post("/learn/courses/{$this->offering->id}/media", ['file' => UploadedFile::fake()->image('x.png')])->assertForbidden();
        $this->actingAs($this->lecturer)->postJson("/learn/courses/{$this->offering->id}/media", ['file' => UploadedFile::fake()->create('x.html', 1, 'text/html')])->assertStatus(422);
    }

    public function test_reviews_are_by_students_answered_by_lecturers_and_can_be_hidden(): void
    {
        $this->actingAs($this->lecturer)->post("/learn/courses/{$this->offering->id}/reviews", ['rating' => 5])->assertForbidden(); // lecturers do not rate their own course
        $this->actingAs($this->student)->post("/learn/courses/{$this->offering->id}/reviews", ['rating' => 4, 'comment' => 'Clear notes', 'anonymous' => true])->assertRedirect();
        $this->actingAs($this->student)->post("/learn/courses/{$this->offering->id}/reviews", ['rating' => 5, 'comment' => 'Even better now'])->assertRedirect(); // one review each, updated
        $other = $this->learner();
        $this->actingAs($other)->post("/learn/courses/{$this->offering->id}/reviews", ['rating' => 2, 'comment' => 'rude words'])->assertRedirect();

        $fb = $this->actingAs($this->lecturer)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props']['feedback']['reviews'];
        $this->assertSame(2, $fb['count']);
        $this->assertSame(3.5, $fb['average']);
        $rude = collect($fb['list'])->firstWhere('rating', 2)['id'];

        Notification::fake();
        $this->actingAs($this->lecturer)->post("/learn/reviews/$rude/reply", ['hidden' => true])->assertRedirect();
        $mine = collect($fb['list'])->firstWhere('rating', 5)['id'];
        $this->actingAs($this->lecturer)->post("/learn/reviews/$mine/reply", ['reply' => 'Thank you'])->assertRedirect();
        Notification::assertSentTo($this->student, LmsNotice::class);

        $seen = $this->actingAs($this->student)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props']['feedback']['reviews'];
        $this->assertSame(1, $seen['count']); // the hidden one is not shown or counted
        $this->assertSame('Thank you', $seen['list'][0]['reply']);
        $this->actingAs($other)->delete("/learn/reviews/$mine")->assertForbidden();
    }

    public function test_polls_one_vote_each_results_as_the_lecturer_chose(): void
    {
        Notification::fake();
        $this->actingAs($this->student)->post("/learn/courses/{$this->offering->id}/polls", ['question' => 'x', 'options' => ['a', 'b'], 'show_results' => 'after_vote'])->assertForbidden();
        $this->actingAs($this->lecturer)->post("/learn/courses/{$this->offering->id}/polls", ['question' => 'Revision day?', 'options' => ['Friday', 'Saturday', 'Monday'], 'show_results' => 'after_close'])->assertRedirect();
        Notification::assertSentTo($this->student, LmsNotice::class);
        $poll = \App\Models\CoursePoll::first();

        $this->actingAs($this->student)->post("/learn/polls/{$poll->id}/vote", ['choices' => [0, 1]])->assertSessionHasErrors('choices'); // one choice only
        $this->actingAs($this->student)->post("/learn/polls/{$poll->id}/vote", ['choices' => [1]])->assertRedirect();
        $this->actingAs($this->student)->post("/learn/polls/{$poll->id}/vote", ['choices' => [2]])->assertRedirect(); // changes the vote
        $this->assertSame(1, \App\Models\CoursePollVote::count());

        $p = $this->actingAs($this->student)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props']['feedback']['polls'][0];
        $this->assertNull($p['results']); // hidden until the poll closes
        $this->assertSame([2], $p['mine']);
        $this->assertSame([0, 0, 1], $this->actingAs($this->lecturer)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props']['feedback']['polls'][0]['results']);

        $this->actingAs($this->lecturer)->post("/learn/polls/{$poll->id}/close")->assertRedirect();
        $this->assertSame([0, 0, 1], $this->actingAs($this->student)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props']['feedback']['polls'][0]['results']);
        $this->actingAs($this->student)->post("/learn/polls/{$poll->id}/vote", ['choices' => [0]])->assertStatus(422);
    }

    public function test_students_ask_for_topics_classmates_vote_and_the_lecturer_answers_everyone(): void
    {
        Notification::fake();
        $this->actingAs($this->student)->post("/learn/courses/{$this->offering->id}/requests", ['kind' => 'topic', 'title' => 'Limiting reagents', 'details' => 'More examples please'])->assertRedirect();
        Notification::assertSentTo($this->lecturer, LmsNotice::class);
        $req = TopicRequest::first();
        $this->assertSame(1, $req->votes);

        $other = $this->learner();
        $this->actingAs($other)->post("/learn/topic-requests/{$req->id}/vote")->assertRedirect();
        $this->assertSame(2, $req->fresh()->votes);
        $this->actingAs($other)->post("/learn/topic-requests/{$req->id}/vote")->assertRedirect(); // votes again = takes it back
        $this->assertSame(1, $req->fresh()->votes);
        $this->actingAs($other)->post("/learn/topic-requests/{$req->id}/vote");

        // classmates do not see who asked; the lecturer does
        $this->assertNull($this->actingAs($other)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props']['feedback']['requests'][0]['author']);
        $this->assertSame($this->student->name, $this->actingAs($this->lecturer)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props']['feedback']['requests'][0]['author']);

        $notes = app(CourseContentService::class)->saveItem($this->offering, ['course_section_id' => $this->week->id, 'type' => 'page', 'title' => 'Limiting reagents', 'body_html' => '<p>…</p>'], null, $this->lecturer);
        $this->actingAs($other)->post("/learn/topic-requests/{$req->id}/respond", ['status' => 'covered'])->assertForbidden();
        $this->actingAs($this->lecturer)->post("/learn/topic-requests/{$req->id}/respond", ['status' => 'covered', 'reply' => 'Added this week', 'course_item_id' => $notes->id])->assertRedirect();
        Notification::assertSentTo([$this->student, $other], LmsNotice::class, fn ($n) => str_contains($n->title, 'is now covered'));
        $this->assertSame('covered', $req->fresh()->status);
    }

    public function test_assessments_are_listed_with_where_each_student_stands(): void
    {
        $a = app(CourseContentService::class)->saveItem($this->offering, ['course_section_id' => $this->week->id, 'type' => 'assignment', 'title' => 'Problem set', 'due_at' => now()->addDay(), 'settings' => ['max_score' => 20]], null, $this->lecturer);
        $list = $this->actingAs($this->student)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props']['assessments'];
        $this->assertSame('to_do', $list[0]['state']);
        \App\Models\AssignmentSubmission::create(['course_item_id' => $a->id, 'user_id' => $this->student->id, 'attempt' => 1, 'status' => 'submitted', 'submitted_at' => now(), 'text' => 'answer', 'anon_code' => 'A1B2C3']);
        $this->assertSame('submitted', $this->actingAs($this->student)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props']['assessments'][0]['state']);
        $t = $this->actingAs($this->lecturer)->get("/learn/courses/{$this->offering->id}")->viewData('page')['props']['assessments'][0];
        $this->assertSame([1, 1, 1], [$t['submitted'], $t['to_mark'], $t['enrolled']]);
    }
}
