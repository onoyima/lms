<?php

namespace Tests\Feature\Exams;

use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\Plan;
use App\Models\Question;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use Database\Seeders\PlanSeeder;

trait BuildsExams
{
    protected User $officer;

    protected function setUpPlatform(string $plan = 'enterprise'): void
    {
        $this->seed(PlanSeeder::class);
        $this->tenant->update(['plan_id' => Plan::where('key', $plan)->value('id')]);
        app(TenantProvisioner::class)->provision(['slug' => $this->tenant->slug, 'name' => $this->tenant->name], $plan);
        $this->officer = User::factory()->create(['name' => 'Exams Officer']);
        $this->officer->assignRole('institution_admin');
    }

    /** @return list<Question> */
    protected function questions(): array
    {
        $make = function (string $type, string $stem, array $options = [], float $mark = 1, ?array $numeric = null) {
            $q = Question::create(['type' => $type, 'stem_html' => "<p>$stem</p>", 'mark' => $mark, 'status' => 'approved', 'numeric_answer' => $numeric, 'topic' => 'General']);
            foreach ($options as $i => [$label, $correct]) {
                $q->options()->create(['label' => chr(65 + $i), 'body_html' => $label, 'is_correct' => $correct, 'position' => $i]);
            }

            return $q;
        };

        return [
            $make('single_choice', 'Balance \(\ce{H2 + O2 -> H2O}\)', [['1', false], ['2', true], ['3', false]]),
            $make('multiple_choice', 'Noble gases?', [['Neon', true], ['Nitrogen', false], ['Argon', true]], 2),
            $make('numeric', 'g to 1 d.p.', [], 1, ['value' => 9.8, 'tolerance' => 0.05]),
            $make('essay', 'Explain osmosis.', [], 5),
        ];
    }

    protected function cbtExam(array $overrides = [], ?array $questions = null): Exam
    {
        $exam = Exam::create(array_merge([
            'title' => 'CHM 101 Exam', 'type' => 'mixed', 'status' => 'scheduled', 'duration_minutes' => 30, 'max_attempts' => 1,
            'total_mark' => 100, 'created_by' => $this->officer->id,
            'settings' => ['shuffle_questions' => false, 'shuffle_options' => false, 'show_score' => true, 'require_check_in' => false],
            'proctoring' => ['require_consent' => false, 'violation_threshold' => 3, 'violation_action' => 'lock'],
            'channels' => ['web', 'desktop', 'seb'],
        ], $overrides));
        $section = $exam->sections()->create(['title' => 'A', 'position' => 0]);
        foreach ($questions ?? $this->questions() as $i => $q) {
            $section->questions()->attach($q->id, ['position' => $i]);
        }

        return $exam;
    }

    protected function sitting(Exam $exam, array $overrides = []): ExamSession
    {
        return $exam->sessions()->create(array_merge([
            'mode' => 'remote', 'starts_at' => now()->subMinutes(5), 'ends_at' => now()->addHours(2), 'late_entry_minutes' => 30, 'status' => 'open',
        ], $overrides));
    }

    protected function candidate(ExamSession $session, ?User $user = null, array $overrides = []): ExamCandidate
    {
        $user ??= User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/'.fake()->unique()->numerify('####')]);

        return ExamCandidate::create(array_merge(['exam_session_id' => $session->id, 'user_id' => $user->id], $overrides));
    }

    protected function startAs(ExamCandidate $c, array $body = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($c->user)->withHeaders($headers)->postJson("/my/exams/{$c->id}/start", $body);
    }

    protected function api(string $token, string $method, string $path, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer $token"])->json($method, "/api/exam/attempt/$path", $data);
    }
}
