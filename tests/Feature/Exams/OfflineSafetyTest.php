<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\ProctoringEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Nobody loses an exam to a bad connection or a power cut: answers written offline arrive later and
 * are kept if written in time; the server waits for a computer it lost contact with; time is given
 * back when the computer itself went off, never twice and never beyond the exam's limit.
 */
class OfflineSafetyTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    private function begin(array $settings = [], int $minutes = 10): array
    {
        $exam = $this->cbtExam(['duration_minutes' => $minutes, 'settings' => array_merge(['shuffle_questions' => false, 'shuffle_options' => false, 'require_check_in' => false], $settings)]);
        $c = $this->candidate($this->sitting($exam));
        $token = $this->startAs($c)->assertOk()->json('token');
        $qs = $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions');

        return [$token, $qs, $c];
    }

    private function pick(array $q): array
    {
        return ['options' => [$q['options'][0]['id']]];
    }

    private function ms(\DateTimeInterface $t): int
    {
        return (int) ($t->format('U') * 1000 + (int) $t->format('v'));
    }

    public function test_answers_written_offline_in_time_are_kept_after_time_is_up(): void
    {
        [$token, $qs] = $this->begin();
        $this->travel(3)->minutes();
        $writtenAt = $this->ms(now());          // the connection drops here; the candidate keeps writing
        $this->travel(9)->minutes();            // time is up while offline

        // the server held the attempt open: it lost contact before the deadline
        $this->artisan('exams:sweep')->assertSuccessful();
        $this->assertSame('in_progress', Attempt::first()->status);

        // back online: the answer written at minute 3 is kept, one written after the end is refused
        $res = $this->api($token, 'POST', 'answers', ['answers' => [
            ['question_id' => $qs[0]['id'], 'seq' => 1, 'at' => $writtenAt, 'response' => $this->pick($qs[0])],
            ['question_id' => $qs[1]['id'], 'seq' => 2, 'at' => $this->ms(now()), 'response' => $this->pick($qs[1])],
        ]])->assertOk();
        $this->assertSame([$qs[0]['id']], $res->json('saved'));
        $this->assertSame([$qs[1]['id']], $res->json('rejected'));

        $a = Attempt::first();
        $this->assertSame('auto_submitted', $a->status);
        $this->assertSame(1, $a->answered_count);
        $this->assertDatabaseHas('attempt_answers', ['attempt_id' => $a->id, 'question_id' => $qs[0]['id']]);
        $this->assertSame(1, ProctoringEvent::where('type', 'late_sync')->count());
        $this->assertNotNull($a->score_raw);
    }

    public function test_a_queued_submit_after_closing_still_delivers_answers_written_in_time(): void
    {
        [$token, $qs] = $this->begin();
        $this->travel(9)->minutes();
        $writtenAt = $this->ms(now());
        $this->travel(150)->minutes();          // past the sync window: the sweeper closed it
        $this->artisan('exams:sweep')->assertSuccessful();
        $this->assertSame('auto_submitted', Attempt::first()->status);

        // within the window after closing, the computer's final submit still lands
        $this->travel(-140)->minutes();
        Attempt::query()->update(['submitted_at' => now()->subMinutes(5)]);
        $this->api($token, 'POST', 'submit', ['answers' => [
            ['question_id' => $qs[2]['id'], 'seq' => 4, 'at' => $writtenAt, 'response' => ['number' => 9.81]],
        ]])->assertOk();
        $this->assertDatabaseHas('attempt_answers', ['question_id' => $qs[2]['id']]);
    }

    public function test_answers_from_a_closed_attempt_without_a_time_are_refused(): void
    {
        [$token, $qs] = $this->begin();
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $qs[0]['id'], 'seq' => 1, 'response' => $this->pick($qs[0])]]])->assertOk();
        $this->travel(11)->minutes();
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $qs[1]['id'], 'seq' => 2, 'response' => $this->pick($qs[1])]]])->assertStatus(409);
        $this->assertNull(DB::table('attempt_answers')->where('question_id', $qs[1]['id'])->value('response'));
    }

    public function test_time_is_given_back_when_the_computer_went_off(): void
    {
        [$token, $qs] = $this->begin(['power_cut_minutes' => 30]);
        $deadline = Attempt::first()->deadline_at;
        $this->travel(2)->minutes();
        $this->api($token, 'POST', 'heartbeat', ['status' => 'active'])->assertOk();
        $off = $this->ms(now()->addSeconds(5));   // power cut just after the last check-in
        $this->travel(6)->minutes();

        $this->api($token, 'POST', 'answers', [
            'answers' => [['question_id' => $qs[0]['id'], 'seq' => 1, 'at' => $this->ms(now()), 'response' => $this->pick($qs[0])]],
            'off' => ['from' => $off, 'to' => $this->ms(now())],
        ])->assertOk()->assertJsonPath('credited', 355);

        $a = Attempt::first();
        $this->assertSame(355, $a->device_off_seconds);
        $this->assertSame(355, (int) $deadline->diffInSeconds($a->deadline_at));
        $this->assertSame(1, ProctoringEvent::where('type', 'device_off')->count());

        // the same report sent again is not counted twice
        $this->api($token, 'POST', 'heartbeat', ['status' => 'active', 'off' => ['from' => $off, 'to' => $this->ms(now())]])->assertJsonPath('credited', 0);
    }

    public function test_time_credit_cannot_cover_time_the_server_heard_from_the_computer_or_exceed_the_limit(): void
    {
        [$token] = $this->begin(['power_cut_minutes' => 2], 60);
        $claimFrom = $this->ms(now());
        $this->travel(5)->minutes();
        $this->api($token, 'POST', 'heartbeat', ['status' => 'active'])->assertOk(); // it was running then
        $this->travel(5)->minutes();

        // claims ten minutes off, but the server heard from it at minute 5: at most the last 5 minutes, capped at 2
        $this->api($token, 'POST', 'heartbeat', ['status' => 'active', 'off' => ['from' => $claimFrom, 'to' => $this->ms(now())]])->assertJsonPath('credited', 120);
        $this->travel(5)->minutes();
        $this->api($token, 'POST', 'heartbeat', ['status' => 'active', 'off' => ['from' => $this->ms(now()->subMinutes(4)), 'to' => $this->ms(now())]])->assertJsonPath('credited', 0);
        $this->assertSame(120, Attempt::first()->device_off_seconds);
    }

    public function test_coming_back_through_the_start_page_also_gives_the_time_back(): void
    {
        [, , $c] = $this->begin(['power_cut_minutes' => 30], 30);
        $this->travel(1)->minutes();
        $off = $this->ms(now());
        $this->travel(4)->minutes();
        $this->startAs($c, ['off' => ['from' => $off, 'to' => $this->ms(now())]])->assertOk()->assertJsonPath('resumed', true);
        $this->assertSame(240, Attempt::first()->device_off_seconds);
    }

    public function test_a_replaced_offline_computer_can_only_fill_unanswered_questions(): void
    {
        [$old, $qs, $c] = $this->begin([], 30);
        $writtenOffline = $this->ms(now());
        $this->travel(1)->minutes();

        // the candidate moves to another computer and answers question 1 there
        $new = $this->startAs($c)->json('token');
        $this->api($new, 'POST', 'answers', ['answers' => [['question_id' => $qs[0]['id'], 'seq' => 1, 'at' => $this->ms(now()), 'response' => ['options' => [$qs[0]['options'][1]['id']]]]]])->assertOk();

        // the first computer comes back online with what it saved while offline
        $res = $this->api($old, 'POST', 'answers', ['answers' => [
            ['question_id' => $qs[0]['id'], 'seq' => 9, 'at' => $writtenOffline, 'response' => $this->pick($qs[0])],
            ['question_id' => $qs[1]['id'], 'seq' => 10, 'at' => $writtenOffline, 'response' => $this->pick($qs[1])],
        ]])->assertStatus(409)->assertJsonPath('reason', 'session_moved');
        $this->assertSame([$qs[1]['id']], $res->json('kept'));

        $a = Attempt::first();
        $q1 = json_decode(DB::table('attempt_answers')->where('attempt_id', $a->id)->where('question_id', $qs[0]['id'])->value('response'), true);
        $this->assertSame([$qs[0]['options'][1]['id']], $q1['options']); // the newer answer stands
        $this->assertDatabaseHas('attempt_answers', ['attempt_id' => $a->id, 'question_id' => $qs[1]['id']]);
        // the old window still cannot read the paper or submit
        $this->api($old, 'GET', 'paper')->assertStatus(409);
        $this->api($old, 'POST', 'submit')->assertStatus(409);
    }
}
