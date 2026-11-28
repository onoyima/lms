<?php

namespace Tests\Feature\Lms;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseItem;
use App\Models\CourseOffering;
use App\Models\CourseSection;
use App\Models\Enrolment;
use App\Models\GradeItem;
use App\Models\Semester;
use App\Models\User;
use App\Services\Lms\GradebookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/**
 * Lecturers see the courses assigned to them (never the student pages), administrators see and
 * manage every course, the lecturer sees who has studied which material, and marks can be typed
 * one after another or uploaded for a whole column.
 */
class StaffCoursesTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private User $lecturer;

    private CourseOffering $mine;

    private CourseOffering $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        Storage::fake('local');
        $sem = Semester::create(['academic_session_id' => AcademicSession::create(['name' => '2026/2027', 'is_current' => true])->id, 'name' => 'First', 'is_current' => true]);
        $this->lecturer = User::factory()->create(['user_type' => 'staff']);
        $this->lecturer->assignRole('lecturer');
        $this->mine = CourseOffering::create(['course_id' => Course::create(['code' => 'PHY 101', 'title' => 'Physics', 'credit_units' => 3, 'level' => 100])->id, 'semester_id' => $sem->id, 'status' => 'open']);
        $this->other = CourseOffering::create(['course_id' => Course::create(['code' => 'BIO 101', 'title' => 'Biology', 'credit_units' => 3, 'level' => 100])->id, 'semester_id' => $sem->id, 'status' => 'open']);
        $this->mine->staff()->attach($this->lecturer->id, ['role' => 'coordinator']);
    }

    private function student(string $matric): User
    {
        $u = User::factory()->create(['user_type' => 'student', 'identifier' => $matric]);
        $u->assignRole('student');
        Enrolment::create(['course_offering_id' => $this->mine->id, 'user_id' => $u->id, 'status' => 'active']);

        return $u;
    }

    public function test_a_lecturer_sees_only_the_courses_assigned_and_never_the_student_view(): void
    {
        // even if the lecturer is also on a class list somewhere, the courses page stays the staff page
        Enrolment::create(['course_offering_id' => $this->other->id, 'user_id' => $this->lecturer->id, 'status' => 'active']);
        $this->actingAs($this->lecturer)->get('/learn/courses')->assertInertia(fn ($p) => $p
            ->where('staff', true)->where('oversees', false)
            ->has('teaching', 1)->where('teaching.0.code', 'PHY 101')
            ->where('enrolled', [])->where('upcoming', []));
        $this->get("/learn/courses/{$this->other->id}/gradebook")->assertForbidden();
    }

    public function test_an_administrator_sees_and_manages_every_course(): void
    {
        $this->actingAs($this->officer)->get('/learn/courses')->assertInertia(fn ($p) => $p->where('oversees', true)->has('teaching', 2));
        $this->get('/learn/courses?q=BIO')->assertInertia(fn ($p) => $p->has('teaching', 1)->where('teaching.0.code', 'BIO 101')->where('teaching.0.role', 'oversight'));
        $this->get("/learn/courses/{$this->other->id}/gradebook")->assertOk();
        $this->get("/learn/courses/{$this->other->id}/progress")->assertOk();
    }

    public function test_the_lecturer_sees_who_opened_each_material(): void
    {
        $section = CourseSection::create(['course_offering_id' => $this->mine->id, 'title' => 'Week 1']);
        $notes = CourseItem::create(['course_offering_id' => $this->mine->id, 'course_section_id' => $section->id, 'type' => 'page', 'title' => 'Motion notes', 'visible' => true, 'body_html' => '<p>v = u + at</p>']);
        CourseItem::create(['course_offering_id' => $this->mine->id, 'course_section_id' => $section->id, 'type' => 'page', 'title' => 'Forces', 'visible' => true, 'body_html' => '<p>F = ma</p>']);
        $a = $this->student('VUG/26/0001');
        $a->update(['name' => 'Ada Obi']);
        $b = $this->student('VUG/26/0002');
        $b->update(['name' => 'Bola Ade']);
        $this->actingAs($a)->get("/learn/items/{$notes->id}")->assertOk();
        $this->actingAs($a)->get("/learn/items/{$notes->id}")->assertOk();

        $this->assertSame(2, (int) DB::table('item_views')->where('user_id', $a->id)->value('views'));
        $this->actingAs($b)->get("/learn/courses/{$this->mine->id}/progress")->assertForbidden();
        $this->actingAs($this->lecturer)->get("/learn/courses/{$this->mine->id}/progress")->assertInertia(fn ($p) => $p
            ->where('enrolled', 2)
            ->where('rows.0.name', $a->name)->where('rows.0.opened', 1)
            ->where('rows.1.opened', 0)
            ->where('items.0.title', 'Motion notes')->where('items.0.opened', 1)->where('items.0.views', 2));
        $csv = $this->get("/learn/courses/{$this->mine->id}/progress/export")->streamedContent();
        $this->assertStringContainsString('Motion notes', $csv);
    }

    public function test_marks_for_a_column_are_uploaded_and_a_wrong_line_saves_nothing(): void
    {
        [$a, $b] = [$this->student('VUG/26/0001'), $this->student('VUG/26/0002')];
        app(GradebookService::class)->ensureDefaults($this->mine);
        $ca = $this->mine->gradeCategories()->first();
        $this->actingAs($this->lecturer)->post("/learn/courses/{$this->mine->id}/gradebook/items", ['title' => 'Practical', 'max_score' => 20, 'grade_category_id' => $ca->id]);
        $col = GradeItem::where('title', 'Practical')->sole();

        // one wrong line: nothing is saved, and the line is named
        $this->post("/learn/grade-items/{$col->id}/upload", ['text' => "Matric,Score\nVUG/26/0001,14\nVUG/26/9999,12"])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Line 3') && str_contains($m, 'VUG/26/9999'));
        $this->assertSame(0, DB::table('grade_entries')->where('grade_item_id', $col->id)->count());

        $this->post("/learn/grade-items/{$col->id}/upload", ['text' => "Matric,Score\nVUG/26/0001,14\nVUG/26/0002,25"])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'more than 20'));

        $file = UploadedFile::fake()->createWithContent('marks.csv', "Name,Matric,Mark\nAda,VUG/26/0001,14\nBola,VUG/26/0002,17.5\n");
        $this->post("/learn/grade-items/{$col->id}/upload", ['file' => $file])->assertSessionHas('success', fn ($m) => str_contains($m, '2 mark'));
        $this->assertEquals(17.5, (float) DB::table('grade_entries')->where('grade_item_id', $col->id)->where('user_id', $b->id)->value('score'));

        // students cannot upload marks
        $this->actingAs($a)->post("/learn/grade-items/{$col->id}/upload", ['text' => 'VUG/26/0001,20'])->assertForbidden();
    }

    public function test_staff_get_my_exams_only_for_staff_exams(): void
    {
        $this->actingAs($this->lecturer)->get('/dashboard')->assertInertia(fn ($p) => $p->where('auth.sitsExams', false));
    }
}
