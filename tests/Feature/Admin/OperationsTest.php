<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Reports\ReportCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    public function test_maintenance_is_announced_blocks_new_exams_and_bookings(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam();
        $later = $this->sitting($exam, ['starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(4), 'status' => 'scheduled']);

        // cannot schedule maintenance over a booked sitting
        $this->actingAs($this->officer)->post('/admin/maintenance', ['starts_at' => now()->addHours(2)->toDateTimeString(), 'ends_at' => now()->addHours(5)->toDateTimeString(), 'message' => 'Upgrade', 'blocks_exams' => true])
            ->assertSessionHasErrors('starts_at');
        $later->delete();
        $this->actingAs($this->officer)->post('/admin/maintenance', ['starts_at' => now()->addMinute()->toDateTimeString(), 'ends_at' => now()->addHours(2)->toDateTimeString(), 'message' => 'Database upgrade', 'blocks_exams' => true])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->officer)->get('/dashboard')->assertInertia(fn ($p) => $p->where('maintenance.0.message', 'Database upgrade'));

        // no sitting can be booked into it
        $this->actingAs($this->officer)->post("/exams/{$exam->id}/sessions", ['mode' => 'remote', 'starts_at' => now()->addHour()->toDateTimeString(), 'ends_at' => now()->addHours(3)->toDateTimeString()])
            ->assertSessionHasErrors('starts_at');

        // once it starts, nobody can start an exam
        DB::table('maintenance_windows')->update(['starts_at' => now()->subMinute()]);
        \App\Support\Maintenance::forget(tenant_id());
        $c = $this->candidate($this->sitting($exam));
        $this->startAs($c)->assertStatus(503)->assertJsonPath('reason', 'maintenance');

        $student = User::factory()->create();
        $student->assignRole('student');
        $this->actingAs($student)->get('/admin/maintenance')->assertForbidden();
    }

    public function test_post_exam_review_report(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam();
        $s = $this->sitting($exam, ['starts_at' => now()->subMinutes(10)]);
        $c = $this->candidate($s);
        $token = $this->startAs($c)->json('token');
        $this->api($token, 'POST', 'help', ['body' => 'Screen froze']);
        $this->api($token, 'POST', 'heartbeat', ['events' => [['type' => 'network_offline'], ['type' => 'multiple_faces']]]);
        $this->api($token, 'POST', 'submit');

        ['columns' => $cols, 'rows' => $rows] = app(ReportCatalog::class)->run('post_exam_review', [], $this->officer);
        $this->assertContains('Help requests', $cols);
        $row = $rows[0];
        $this->assertSame(1, $row['candidates']);
        $this->assertSame(1, $row['submitted']);
        $this->assertSame(1, $row['help']);
        $this->assertSame(1, $row['disconnections']);
        $this->assertSame(1, $row['environment']);
    }
}
