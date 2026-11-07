<?php

namespace Tests\Feature\Exams;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyAndLoginTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    public function test_legacy_import_refuses_any_non_local_host(): void
    {
        foreach (['db.example.com', '10.0.0.5', 'veritas.edu.ng', '127.0.0.2'] as $host) {
            $this->artisan('legacy:import', ['--host' => $host])
                ->expectsOutputToContain('Refusing to connect')
                ->assertFailed();
        }
    }

    public function test_students_can_sign_in_with_matric_number(): void
    {
        $this->setUpPlatform();
        $user = User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/CHM/22/001', 'password' => 'secret-pass-1']);

        $this->post('/login', ['email' => 'vug/chm/22/001', 'password' => 'secret-pass-1'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_accounts_cannot_sign_in(): void
    {
        $this->setUpPlatform();
        User::factory()->create(['identifier' => 'VUG/9', 'password' => 'secret-pass-1', 'status' => 'inactive']);

        $this->post('/login', ['email' => 'VUG/9', 'password' => 'secret-pass-1']);
        $this->assertGuest();
    }

    public function test_questions_to_show_draws_a_subset_per_candidate(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam();
        $exam->sections()->first()->update(['blueprint' => [['draw' => 2]]]);
        $candidate = $this->candidate($this->sitting($exam));

        $token = $this->startAs($candidate)->assertOk()->json('token');
        $paper = $this->api($token, 'GET', 'paper')->assertOk()->json('paper');

        $this->assertCount(2, collect($paper['sections'])->flatMap(fn ($s) => $s['questions']));
    }
}
