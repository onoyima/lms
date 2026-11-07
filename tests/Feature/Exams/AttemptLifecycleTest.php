<?php

namespace Tests\Feature\Exams;

use App\Models\Attempt;
use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttemptLifecycleTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    public function test_full_attempt_with_auto_marking_and_scaling(): void
    {
        $exam = $this->cbtExam();
        $c = $this->candidate($this->sitting($exam));

        $token = $this->startAs($c)->assertOk()->json('token');
        $paper = $this->api($token, 'GET', 'paper')->assertOk()->json();

        // candidate copy never carries the key
        $this->assertStringNotContainsString('correct', json_encode($paper['paper']));
        [$q1, $q2, $q3, $q4] = $paper['paper']['sections'][0]['questions'];
        $right = fn ($q, $labels) => collect($q['options'])->whereIn('html', $labels)->pluck('id')->all();

        $this->api($token, 'POST', 'answers', ['answers' => [
            ['question_id' => $q1['id'], 'seq' => 1, 'response' => ['options' => $right($q1, ['2'])]],
            ['question_id' => $q2['id'], 'seq' => 2, 'response' => ['options' => $right($q2, ['Neon', 'Argon'])]],
            ['question_id' => $q3['id'], 'seq' => 3, 'response' => ['number' => 9.81]],
            ['question_id' => $q4['id'], 'seq' => 4, 'response' => ['text' => 'Water moves across a membrane...']],
        ]])->assertOk()->assertJsonPath('rejected', []);

        $res = $this->api($token, 'POST', 'submit')->assertOk();
        $attempt = Attempt::first();
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame(4.0, $attempt->score_raw);   // 1 + 2 + 1, essay waits
        $this->assertSame(9.0, $attempt->score_max);
        $this->assertTrue($attempt->needs_marking);
        $this->assertNull($res->json('score'));         // not shown while an essay is unmarked
        $this->assertNotNull($res->json('receipt.hash'));
        $this->assertSame('pending_marking', Result::first()->status);

        // submitting twice is harmless
        $this->api($token, 'POST', 'submit')->assertOk();
        $this->assertSame(1, Attempt::count());
        // and nothing can be saved after
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q1['id'], 'seq' => 9, 'response' => null]]])->assertStatus(409);
    }

    public function test_saves_are_idempotent_and_newest_sequence_wins(): void
    {
        $exam = $this->cbtExam();
        $c = $this->candidate($this->sitting($exam));
        $token = $this->startAs($c)->json('token');
        $q = $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions.0');
        [$a, $b] = array_column($q['options'], 'id');

        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q['id'], 'seq' => 5, 'response' => ['options' => [$b]]]]]);
        // an older retry arriving late must not overwrite the newer answer
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q['id'], 'seq' => 3, 'response' => ['options' => [$a]]]]]);
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q['id'], 'seq' => 5, 'response' => ['options' => [$b]]]]]);

        $saved = $this->api($token, 'GET', 'paper')->json("answers.{$q['id']}");
        $this->assertSame([$b], $saved['response']['options']);
        $this->assertSame(5, $saved['seq']);
    }

    public function test_answers_with_foreign_options_or_questions_are_rejected(): void
    {
        $exam = $this->cbtExam();
        $c = $this->candidate($this->sitting($exam));
        $token = $this->startAs($c)->json('token');
        $q = $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions.0');

        $res = $this->api($token, 'POST', 'answers', ['answers' => [
            ['question_id' => $q['id'], 'seq' => 1, 'response' => ['options' => [999999]]],
            ['question_id' => 424242, 'seq' => 2, 'response' => ['text' => 'x']],
        ]])->assertOk();
        $this->assertEqualsCanonicalizing([$q['id'], 424242], $res->json('rejected'));
    }

    public function test_opening_the_exam_elsewhere_stops_the_old_window(): void
    {
        $exam = $this->cbtExam();
        $c = $this->candidate($this->sitting($exam));
        $old = $this->startAs($c)->json('token');
        $new = $this->startAs($c)->assertJsonPath('resumed', true)->json('token');

        $this->api($old, 'GET', 'paper')->assertStatus(409)->assertJsonPath('reason', 'session_moved');
        $this->api($new, 'GET', 'paper')->assertOk();
        $this->assertSame(1, Attempt::count());
        $this->assertDatabaseHas('security_events', ['type' => 'token_rejected']);
    }

    public function test_the_server_clock_decides_and_late_answers_are_refused(): void
    {
        $exam = $this->cbtExam(['duration_minutes' => 10]);
        $c = $this->candidate($this->sitting($exam));
        $token = $this->startAs($c)->json('token');
        $q = $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions.0');

        $this->travel(11)->minutes();
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q['id'], 'seq' => 1, 'response' => ['options' => [$q['options'][0]['id']]]]]])->assertStatus(409);
        $this->assertSame('auto_submitted', Attempt::first()->status);
        $this->assertSame('timeout', Attempt::first()->submit_reason);
    }

    public function test_the_sweeper_submits_abandoned_attempts(): void
    {
        $exam = $this->cbtExam(['duration_minutes' => 5]);
        $this->startAs($this->candidate($this->sitting($exam)));
        $this->travel(10)->minutes();

        $this->artisan('exams:sweep')->assertSuccessful();
        $this->assertSame('auto_submitted', Attempt::first()->status);
    }

    public function test_extra_time_accommodation_extends_the_deadline(): void
    {
        $exam = $this->cbtExam(['duration_minutes' => 30]);
        $c = $this->candidate($this->sitting($exam), null, ['extra_time_minutes' => 15]);
        $this->startAs($c);

        $remaining = Attempt::first()->remainingSeconds();
        $this->assertGreaterThan(44 * 60, $remaining);
        $this->assertLessThanOrEqual(45 * 60, $remaining);
    }

    public function test_access_rules_window_pin_eligibility_consent_and_attempts(): void
    {
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => true]]);
        $later = $this->sitting($exam, ['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(3)]);
        $this->startAs($this->candidate($later))->assertStatus(403)->assertJsonPath('reason', 'not_started');

        $s = $this->sitting($exam, ['access_pin_hash' => Hash::make('4321')]);
        $c = $this->candidate($s);
        $this->startAs($c, ['consent' => true])->assertJsonPath('reason', 'pin_required');
        $this->startAs($c, ['pin' => '4321'])->assertJsonPath('reason', 'consent_required');
        $this->startAs($c, ['pin' => '4321', 'consent' => true])->assertOk();

        $inel = $this->candidate($s, null, ['eligibility' => 'ineligible', 'eligibility_reason' => 'Not registered']);
        $this->startAs($inel, ['pin' => '4321', 'consent' => true])->assertJsonPath('reason', 'ineligible');

        // one attempt allowed
        $token = $this->startAs($c, ['pin' => '4321', 'consent' => true])->json('token');
        $this->api($token, 'POST', 'submit');
        $this->startAs($c, ['pin' => '4321', 'consent' => true])->assertJsonPath('reason', 'no_attempts_left');
    }

    public function test_late_entry_closes(): void
    {
        $exam = $this->cbtExam();
        $s = $this->sitting($exam, ['starts_at' => now()->subMinutes(40), 'late_entry_minutes' => 30]);

        $this->startAs($this->candidate($s))->assertJsonPath('reason', 'late');
    }

    public function test_unapproved_exams_cannot_be_started_except_practice(): void
    {
        $exam = $this->cbtExam(['status' => 'draft']);
        $this->startAs($this->candidate($this->sitting($exam)))->assertJsonPath('reason', 'not_scheduled');
        $this->startAs($this->candidate($this->sitting($exam, ['is_practice' => true])))->assertOk();
    }

    public function test_hall_sittings_need_check_in(): void
    {
        $exam = $this->cbtExam(['settings' => ['require_check_in' => true, 'shuffle_questions' => false]]);
        $c = $this->candidate($this->sitting($exam, ['mode' => 'hall']));
        $this->startAs($c)->assertJsonPath('reason', 'not_checked_in');

        $this->actingAs($this->officer)->post("/sessions/{$c->exam_session_id}/candidates/{$c->id}/check-in")->assertRedirect();
        $this->startAs($c)->assertOk();
    }

    public function test_violations_lock_the_attempt_and_resume_gives_time_back(): void
    {
        $exam = $this->cbtExam();
        $c = $this->candidate($this->sitting($exam));
        $token = $this->startAs($c)->json('token');

        $this->api($token, 'POST', 'heartbeat', ['status' => 'blur', 'events' => [
            ['type' => 'tab_hidden'], ['type' => 'fullscreen_exit'], ['type' => 'tab_hidden'],
        ]])->assertOk()->assertJsonPath('attempt.status', 'locked');

        $attempt = Attempt::first();
        $this->assertSame(3, $attempt->violation_count);
        $this->assertGreaterThan(0, $attempt->risk_score);
        $before = $attempt->deadline_at->copy();

        $this->travel(5)->minutes();
        $this->actingAs($this->officer)->postJson("/proctor/attempts/{$attempt->id}/action", ['action' => 'unlock'])->assertOk();
        $attempt->refresh();
        $this->assertSame('in_progress', $attempt->status);
        $this->assertSame(300, (int) $before->diffInSeconds($attempt->deadline_at));
    }

    public function test_ai_alerts_open_an_incident_for_human_review(): void
    {
        $exam = $this->cbtExam();
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        $this->api($token, 'POST', 'heartbeat', ['events' => [['type' => 'multiple_faces', 'data' => ['faces' => 2]]]])->assertOk();
        $this->api($token, 'POST', 'heartbeat', ['events' => [['type' => 'multiple_faces']]])->assertOk();

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseHas('incidents', ['type' => 'ai_alert', 'needs_human_review' => true, 'status' => 'open']);
    }

    public function test_proctor_messages_reach_the_candidate_and_can_be_acknowledged(): void
    {
        $exam = $this->cbtExam();
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');
        $attempt = Attempt::first();
        $this->actingAs($this->officer)->postJson("/proctor/attempts/{$attempt->id}/action", ['action' => 'warn', 'body' => 'Eyes on your screen'])->assertOk();

        $msg = $this->api($token, 'POST', 'heartbeat')->json('messages.0');
        $this->assertSame('Eyes on your screen', $msg['body']);
        $this->api($token, 'POST', "messages/{$msg['id']}/ack")->assertOk();
        $this->assertSame([], $this->api($token, 'POST', 'heartbeat')->json('messages'));
    }

    public function test_emergency_pause_of_a_sitting_blocks_saves_and_restores_time(): void
    {
        $exam = $this->cbtExam();
        $s = $this->sitting($exam);
        $token = $this->startAs($this->candidate($s))->json('token');
        $q = $this->api($token, 'GET', 'paper')->json('paper.sections.0.questions.0');
        $deadline = Attempt::first()->deadline_at->copy();

        $this->actingAs($this->officer)->postJson("/proctor/sessions/{$s->id}/action", ['action' => 'pause', 'reason' => 'Power cut'])->assertOk();
        $this->api($token, 'POST', 'answers', ['answers' => [['question_id' => $q['id'], 'seq' => 1, 'response' => null]]])->assertStatus(423);
        $this->travel(10)->minutes();
        $this->actingAs($this->officer)->postJson("/proctor/sessions/{$s->id}/action", ['action' => 'resume'])->assertOk();

        $this->assertSame(600, (int) $deadline->diffInSeconds(Attempt::first()->deadline_at));
        $this->assertDatabaseHas('incidents', ['type' => 'disruption', 'title' => 'Sitting paused']);
    }

    public function test_score_policy_highest_of_two_attempts(): void
    {
        $qs = $this->questions();
        $exam = $this->cbtExam(['max_attempts' => 2, 'score_policy' => 'highest'], [$qs[0]]);
        $c = $this->candidate($this->sitting($exam));

        $t1 = $this->startAs($c)->json('token');
        $this->api($t1, 'POST', 'submit'); // blank: 0
        $t2 = $this->startAs($c)->json('token');
        $q = $this->api($t2, 'GET', 'paper')->json('paper.sections.0.questions.0');
        $correct = collect($q['options'])->firstWhere('html', '2')['id'];
        $this->api($t2, 'POST', 'submit', ['answers' => [['question_id' => $q['id'], 'seq' => 1, 'response' => ['options' => [$correct]]]]])
            ->assertJsonPath('score.raw', 1);

        $this->assertSame(100.0, Result::first()->final_score);
        $this->assertSame('A', Result::first()->grade);
    }

    public function test_shuffled_papers_differ_between_candidates_but_are_stable_for_one(): void
    {
        $qs = collect(range(1, 12))->map(fn ($i) => tap(\App\Models\Question::create(['type' => 'single_choice', 'stem_html' => "<p>Q$i</p>", 'status' => 'approved']), fn ($q) => $q->options()->createMany([
            ['label' => 'A', 'body_html' => 'a', 'is_correct' => true, 'position' => 0], ['label' => 'B', 'body_html' => 'b', 'is_correct' => false, 'position' => 1],
        ])))->all();
        $exam = $this->cbtExam(['settings' => ['shuffle_questions' => true, 'shuffle_options' => true, 'require_check_in' => false]], $qs);
        $s = $this->sitting($exam);
        $c1 = $this->candidate($s);
        $c2 = $this->candidate($s);

        $order = fn ($c) => collect($this->api($this->startAs($c)->json('token'), 'GET', 'paper')->json('paper.sections.0.questions'))->pluck('id')->all();
        $o1 = $order($c1);
        $this->assertNotSame($o1, $order($c2));
        $this->assertSame($o1, $order($c1)); // resume shows the same paper
    }

    public function test_blueprint_draws_from_the_pool(): void
    {
        foreach (range(1, 10) as $i) {
            \App\Models\Question::create(['type' => 'single_choice', 'stem_html' => "<p>P$i</p>", 'status' => 'approved', 'topic' => $i <= 6 ? 'Acids' : 'Bases', 'difficulty' => 'easy'])
                ->options()->create(['label' => 'A', 'body_html' => 'x', 'is_correct' => true, 'position' => 0]);
        }
        $exam = $this->cbtExam([], []);
        $exam->sections()->first()->update(['blueprint' => [['topic' => 'Acids', 'count' => 4, 'mark' => 2], ['topic' => 'Bases', 'count' => 2]]]);
        $token = $this->startAs($this->candidate($this->sitting($exam)))->json('token');

        $qs = collect($this->api($token, 'GET', 'paper')->json('paper.sections.0.questions'));
        $this->assertCount(6, $qs);
        $this->assertSame(10.0, Attempt::first()->score_max);
    }
}
