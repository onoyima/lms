<?php

namespace Tests\Feature\System;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/**
 * What the institution subscribes to, and the exam board, are for the people whose role covers
 * them. Students and ordinary staff do not see them.
 */
class PrivateToAdminsTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    private function person(string $role, string $type = 'staff'): User
    {
        $u = User::factory()->create(['user_type' => $type]);
        $u->assignRole($role);

        return $u;
    }

    public function test_only_administrators_see_the_subscription(): void
    {
        foreach ([['student', 'student'], ['lecturer', 'staff']] as [$role, $type]) {
            $this->actingAs($this->person($role, $type))->get('/dashboard')->assertInertia(fn ($p) => $p->where('moduleCards', []));
        }
        $admin = User::permission('billing.manage')->first() ?? $this->person('institution_admin');
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($p) => $p->where('moduleCards', fn ($c) => count($c) > 0));
    }

    public function test_students_and_staff_without_exams_cannot_open_the_exam_board(): void
    {
        $this->actingAs($this->person('student', 'student'))->get('/exam-board')->assertForbidden();
        $this->actingAs($this->person('lecturer'))->get('/exam-board')->assertForbidden();
        $this->actingAs($this->officer)->get('/exam-board')->assertOk();
    }

    public function test_system_health_lists_the_settings_that_slow_pages_down(): void
    {
        $this->actingAs($this->officer)->get('/admin/system-health')->assertInertia(fn ($p) => $p
            ->has('speed', 8)->where('speed.0.key', 'opcache')->has('speed.0.fix'));
        $this->actingAs($this->person('lecturer'))->get('/admin/system-health')->assertForbidden();
    }
}
