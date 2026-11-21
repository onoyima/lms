<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\ExamCandidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** O1: staff promotion exams — staff are the candidates, found and uploaded like students, and sit it from their own menu. */
class StaffPromotionExamTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_staff_sit_a_promotion_exam_and_students_cannot_be_added(): void
    {
        $exam = $this->cbtExam(['category' => 'promotion']);
        $session = $this->sitting($exam);
        $nurse = User::factory()->create(['user_type' => 'staff', 'name' => 'Grace Staff', 'identifier' => 'SP/0101']);
        $student = User::factory()->create(['user_type' => 'student', 'name' => 'Grace Student']);

        $this->actingAs($this->officer)->getJson('/student-search?q=grace&audience=staff')->assertJsonCount(1)->assertJsonPath('0.id', $nurse->id);
        $this->post("/sessions/{$session->id}/candidates", ['user_ids' => [$student->id]])->assertStatus(422);
        $this->post("/sessions/{$session->id}/candidates", ['user_ids' => [$nurse->id]])->assertSessionHas('success');
        $this->post("/sessions/{$session->id}/candidates", ['identifier_prefix' => 'SP/']);
        $this->assertSame(1, ExamCandidate::count());

        // an uploaded staff list makes staff accounts, not student ones
        $preview = $this->post('/student-lists/preview', ['file' => UploadedFile::fake()->createWithContent('staff.csv', "staff_no,name\nSP/0202,Bisi Nurse\n")], ['Accept' => 'application/json'])->json();
        $this->post("/sessions/{$session->id}/candidates", ['list_token' => $preview['token'], 'create_missing' => true]);
        $this->assertSame('staff', User::where('identifier', 'SP/0202')->value('user_type'));
        $this->assertSame(0, User::where('identifier', 'staff_no')->count()); // the heading row is not a person

        // the staff member sees it in their own menu and writes it
        $this->actingAs($nurse)->get('/dashboard')->assertInertia(fn ($p) => $p->where('auth.sitsExams', true));
        $token = $this->startAs(ExamCandidate::where('user_id', $nurse->id)->sole())->assertOk()->json('token');
        $this->api($token, 'POST', 'submit')->assertOk();
        $this->assertSame(1, Attempt::where('user_id', $nurse->id)->count());
        $this->actingAs($student)->get('/dashboard')->assertInertia(fn ($p) => $p->where('auth.sitsExams', false));
    }
}
