<?php

namespace Tests\Feature\Exams;

use App\Models\User;
use App\Models\Venue;
use App\Notifications\ExamRescheduled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Moving a sitting tells its candidates; a sitting already under way cannot move. */
class RescheduleTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_moving_a_sitting_notifies_candidates_and_frees_seats(): void
    {
        Notification::fake();
        $exam = $this->cbtExam();
        $hall = Venue::create(['name' => 'Hall A', 'capacity' => 100]);
        $other = Venue::create(['name' => 'Hall B', 'capacity' => 100]);
        $session = $this->sitting($exam, ['mode' => 'hall', 'venue_id' => $hall->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2)]);
        $a = $this->candidate($session);
        $b = $this->candidate($session);

        $to = now()->addDays(2)->setTime(10, 0);
        $this->actingAs($this->officer)->put("/sessions/{$session->id}", ['starts_at' => $to->toIso8601String(), 'ends_at' => $to->copy()->addHours(2)->toIso8601String(), 'venue_id' => $other->id])
            ->assertSessionHas('success', 'Sitting moved. 2 candidate(s) told.');
        $this->assertSame($other->id, $session->fresh()->venue_id);
        $this->assertTrue($session->fresh()->starts_at->equalTo($to));
        Notification::assertSentTo([$a->user, $b->user], ExamRescheduled::class, fn ($n, $ch, $u) => str_contains($n->toArray($u)['body'], 'Hall B') && str_contains($n->toArray($u)['body'], 'was'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'session.rescheduled']);

        // without telling anyone
        Notification::fake();
        $this->actingAs($this->officer)->put("/sessions/{$session->id}", ['starts_at' => $to->copy()->addHour()->toIso8601String(), 'ends_at' => $to->copy()->addHours(3)->toIso8601String(), 'notify' => false]);
        Notification::assertNothingSent();

        // students and lecturers without exams.manage cannot move it
        $student = User::find($a->user_id);
        $this->actingAs($student)->put("/sessions/{$session->id}", ['starts_at' => $to->toIso8601String(), 'ends_at' => $to->copy()->addHour()->toIso8601String()])->assertForbidden();
    }

    public function test_a_sitting_that_has_started_cannot_move(): void
    {
        $exam = $this->cbtExam();
        $session = $this->sitting($exam);
        $c = $this->candidate($session);
        $this->startAs($c)->assertSuccessful();
        $this->actingAs($this->officer)->put("/sessions/{$session->id}", ['starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String()])->assertStatus(409);
    }
}
