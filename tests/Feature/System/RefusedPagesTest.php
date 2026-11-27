<?php

namespace Tests\Feature\System;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/** Moving inside the app to a refused or missing page shows an app page with the reason, Back and Home. */
class RefusedPagesTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    public function test_a_switched_off_module_explains_itself_inside_the_app(): void
    {
        $this->setUpPlatform();
        config(['app.debug' => false]);
        $student = User::factory()->create(['user_type' => 'student']);
        $student->assignRole('student');
        \Illuminate\Support\Facades\DB::table('tenant_modules')->insert(['tenant_id' => $student->tenant_id, 'module' => 'exam_board', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->officer)->get('/exam-board', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request())])
            ->assertStatus(403)->assertJsonPath('component', 'error')->assertJsonPath('props.status', 403);
        $this->actingAs($this->officer)->get('/learn/items/999999', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request())])
            ->assertStatus(404)->assertJsonPath('component', 'error');
        // a full page load still gets the plain error page
        $this->actingAs($this->officer)->get('/exam-board')->assertStatus(403)->assertSee('Go back');
    }
}
