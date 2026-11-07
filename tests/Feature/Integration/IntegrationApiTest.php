<?php

namespace Tests\Feature\Integration;

use App\Models\Programme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private function key(array $scopes = ['sis.write'], ?array $ips = null, ?string $expires = null): string
    {
        DB::table('api_keys')->insert(['tenant_id' => $this->tenant->id, 'name' => 'SIS', 'prefix' => 'abcdEFGH', 'key_hash' => hash('sha256', str_repeat('s', 40)),
            'scopes' => json_encode($scopes), 'allowed_ips' => $ips ? json_encode($ips) : null, 'expires_at' => $expires ?? now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);

        return 'vk_abcdEFGH_'.str_repeat('s', 40);
    }

    public function test_sis_pushes_students_idempotently_with_row_errors(): void
    {
        $this->setUpPlatform();
        $prog = Programme::create(['department_id' => \App\Models\Department::create(['faculty_id' => \App\Models\Faculty::create(['name' => 'S', 'code' => 'S'])->id, 'name' => 'C', 'code' => 'C'])->id, 'name' => 'Chem', 'code' => 'BSC-CHM']);
        $key = $this->key();
        $body = ['data' => [
            ['matric_no' => 'VUG/1', 'name' => 'Ada', 'programme_code' => 'BSC-CHM', 'level' => 200, 'status' => 'active'],
            ['matric_no' => 'VUG/2', 'name' => 'Bola', 'status' => 'withdrawn'],
            ['matric_no' => 'VUG/3', 'name' => 'Bad', 'status' => 'expelled-ish'],
            ['matric_no' => 'VUG/4', 'name' => 'Unknown prog', 'programme_code' => 'NOPE', 'status' => 'active'],
        ]];

        $r = $this->withHeaders(['Authorization' => "Bearer $key", 'Idempotency-Key' => 'batch-1'])->postJson('/api/v1/sis/students', $body)->assertOk();
        $this->assertSame([4, 2, 0, 2], [$r['received'], $r['created'], $r['updated'], $r['failed']]);
        $this->assertSame(2, $r['errors'][0]['row']);
        $this->assertSame('inactive', User::where('identifier', 'VUG/2')->value('status'));   // withdrawn → no access
        $this->assertSame($prog->id, DB::table('student_profiles')->where('external_id', 'VUG/1')->value('programme_id'));

        // Same Idempotency-Key → same answer, nothing applied twice
        $this->withHeaders(['Authorization' => "Bearer $key", 'Idempotency-Key' => 'batch-1'])->postJson('/api/v1/sis/students', $body)->assertOk()->assertJson(['replayed' => true]);
        $this->assertSame(1, DB::table('sync_runs')->count());

        // Status change re-activates / updates (new batch, new key)
        $this->flushHeaders()->withHeaders(['Authorization' => "Bearer $key"])->postJson('/api/v1/sis/students', ['data' => [['matric_no' => 'VUG/2', 'name' => 'Bola A.', 'status' => 'active']]])->assertJson(['updated' => 1]);
        $this->assertSame('active', User::where('identifier', 'VUG/2')->value('status'));
    }

    public function test_keys_are_checked_for_scope_ip_expiry_and_format(): void
    {
        $this->setUpPlatform();
        $key = $this->key(['results.read'], ['10.0.0.0/8']);
        $this->postJson('/api/v1/sis/courses', ['data' => [['code' => 'X', 'title' => 'Y', 'units' => 2]]])->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer vk_abcdEFGH_'.str_repeat('x', 40)])->getJson('/api/v1/results')->assertUnauthorized();
        $this->withHeaders(['Authorization' => "Bearer $key"])->getJson('/api/v1/results')->assertUnauthorized();          // IP not allowed
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->withHeaders(['Authorization' => "Bearer $key"])->getJson('/api/v1/results')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->withHeaders(['Authorization' => "Bearer $key"])
            ->postJson('/api/v1/sis/courses', ['data' => [['code' => 'X', 'title' => 'Y', 'units' => 2]]])->assertForbidden(); // scope
        DB::table('api_keys')->update(['expires_at' => now()->subDay()]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->withHeaders(['Authorization' => "Bearer $key"])->getJson('/api/v1/results')->assertUnauthorized();
        $this->assertGreaterThanOrEqual(4, DB::table('security_events')->where('type', 'api_key_rejected')->count());
    }

    public function test_health_hides_details_without_the_token(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonMissingPath('checks');
        config(['platform.health_token' => 'probe-secret']);
        $this->withHeaders(['X-Health-Token' => 'probe-secret'])->getJson('/api/health')->assertOk()->assertJsonPath('checks.database.ok', true);
    }
}
