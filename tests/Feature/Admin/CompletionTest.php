<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Integrations\Connectors;
use App\Services\Integrations\InstitutionMail;
use App\Services\Monitoring\Alerts;
use App\Services\Monitoring\Metrics;
use App\Services\Monitoring\NetworkMonitor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class CompletionTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        $this->officer->forceFill(['two_factor_confirmed_at' => now()])->save();
        InstitutionMail::reset();
    }

    private function platformAdmin(): User
    {
        $p = User::factory()->create();
        $p->forceFill(['tenant_id' => null, 'is_platform_admin' => true, 'user_type' => 'platform', 'two_factor_confirmed_at' => now()])->save();

        return $p;
    }

    private function as(string $role, string $type = 'staff'): User
    {
        $u = User::factory()->create(['user_type' => $type, 'two_factor_confirmed_at' => now()]);
        $u->assignRole($role);

        return $u;
    }

    public function test_queued_jobs_run_as_the_institution_that_queued_them(): void
    {
        config(['queue.default' => 'database']);
        dispatch(function () {
            Cache::put('job-tenant', tenant_id(), 60);
        });
        app(TenantContext::class)->set(null); // the worker starts with no institution
        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);
        $this->assertSame($this->tenant->id, Cache::get('job-tenant'));
    }

    public function test_an_institution_can_send_mail_through_its_own_server(): void
    {
        $this->actingAs($this->officer)->put('/admin/integrations/mail', ['enabled' => true, 'host' => '93.184.216.34', 'port' => 587, 'encryption' => 'tls',
            'username' => 'exams@uni.edu.ng', 'secret' => 'app-password-123', 'from_address' => 'exams@uni.edu.ng', 'from_name' => 'Exams Office'])->assertRedirect();
        InstitutionMail::apply($this->tenant->fresh());
        $this->assertSame('institution', config('mail.default'));
        $this->assertSame('93.184.216.34', config('mail.mailers.institution.host'));
        $this->assertSame('app-password-123', config('mail.mailers.institution.password'));
        $this->assertSame('exams@uni.edu.ng', config('mail.from.address'));

        app(TenantContext::class)->set(null);
        $this->assertNotSame('institution', config('mail.default')); // the platform's own mail again
        $this->actingAs($this->officer)->put('/admin/integrations/mail', ['enabled' => true, 'host' => '127.0.0.1', 'port' => 25, 'encryption' => 'none', 'from_address' => 'a@b.ng'])->assertStatus(422);
        $this->assertStringNotContainsString('app-password-123', (string) json_encode($this->tenant->fresh()->settings)); // stored encrypted
    }

    public function test_notices_are_copied_to_the_institutions_notification_service(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response(['ok' => true])]);
        app(Connectors::class)->save('notify', ['enabled' => true, 'url' => 'https://93.184.216.34/hook', 'secret' => str_repeat('n', 30), 'urgent_only' => false]);
        $this->tenant->refresh();
        app(TenantContext::class)->set($this->tenant->fresh());
        $student = $this->as('student', 'student');
        $student->notify(new LmsNotice('Exam moved', 'CHM 101 now starts at 10:00.', '/my/exams', true));
        Http::assertSent(fn ($r) => $r['title'] === 'Exam moved' && $r['recipient']['email'] === $student->email
            && $r->hasHeader('X-Platform-Signature', hash_hmac('sha256', $r->body(), str_repeat('n', 30))));
    }

    public function test_requests_are_measured_and_alerts_open_and_close(): void
    {
        Notification::fake();
        $platform = $this->platformAdmin();
        foreach ([80, 90, 120, 3000] as $ms) {
            Metrics::request(200, $ms, true);
        }
        Metrics::request(500, 50, false);
        $d = app(Metrics::class)->collect(now()->startOfMinute());
        $this->assertSame(5, $d['requests']);
        $this->assertSame(1, $d['errors']);
        $this->assertSame(5000, $d['p95_ms']);

        foreach (range(1, 6) as $m) {
            DB::table('system_metrics')->insert(['minute' => now()->subMinutes($m)->startOfMinute(), 'data' => json_encode(['cpu_pct' => 99, 'requests' => 10])]);
        }
        app(Alerts::class)->evaluate();
        $this->assertDatabaseHas('system_alerts', ['key' => 'cpu', 'level' => 'critical', 'resolved_at' => null]);
        Notification::assertSentTo($platform, LmsNotice::class);

        DB::table('system_metrics')->update(['data' => json_encode(['cpu_pct' => 20, 'requests' => 10])]);
        app(Alerts::class)->evaluate();
        $this->assertNotNull(DB::table('system_alerts')->where('key', 'cpu')->value('resolved_at'));

        $this->actingAs($platform)->get('/platform/health')->assertOk()->assertInertia(fn (Assert $p) => $p->component('platform/health')->has('alerts'));
        $this->actingAs($this->officer)->get('/admin/system-health')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/system-health'));
        $this->actingAs($this->as('lecturer'))->get('/admin/system-health')->assertForbidden();
    }

    public function test_hall_network_devices_are_checked_and_failures_reported(): void
    {
        Notification::fake();
        // nothing answers on this network in the test (the sandbox would otherwise accept every connection)
        $this->app->instance(NetworkMonitor::class, new class extends NetworkMonitor
        {
            protected function reach(string $host, int $port): ?int
            {
                return null;
            }
        });
        $this->actingAs($this->officer)->post('/admin/system-health/devices', ['name' => 'Loop', 'kind' => 'switch', 'host' => '127.0.0.1', 'port' => 80])->assertStatus(422);
        $this->actingAs($this->officer)->post('/admin/system-health/devices', ['name' => 'Metadata', 'kind' => 'server', 'host' => '169.254.169.254', 'port' => 80])->assertStatus(422);
        $this->actingAs($this->officer)->post('/admin/system-health/devices', ['name' => 'Hall A switch', 'kind' => 'switch', 'host' => '10.255.255.1', 'port' => 443])->assertRedirect();
        $id = DB::table('network_devices')->value('id');
        foreach (range(1, 2) as $_) {
            app(NetworkMonitor::class)->probe(DB::table('network_devices')->find($id), $this->tenant);
        }
        $this->assertSame('down', DB::table('network_devices')->find($id)->status);
        $this->assertSame(3, DB::table('network_checks')->where('network_device_id', $id)->count());
        Notification::assertSentTo($this->officer, LmsNotice::class, fn ($n) => str_contains($n->title, 'not answering'));
    }

    public function test_a_recovery_drill_is_recorded(): void
    {
        $platform = $this->platformAdmin();
        $this->actingAs($platform)->post('/platform/health/drill')->assertRedirect();
        $drill = DB::table('dr_drills')->latest('id')->first();
        $this->assertNotNull($drill);
        $this->assertNotSame('running', $drill->status);
        $this->assertNotEmpty(json_decode($drill->steps, true));
        $this->actingAs($this->officer)->post('/platform/health/drill')->assertForbidden();
    }

    public function test_sensitive_notes_are_encrypted_in_the_database(): void
    {
        $s = $this->as('student', 'student');
        $this->actingAs($s)->post('/my/exceptions', ['type' => 'accessibility', 'subject' => 'Extra time', 'reason' => 'I have dyslexia; assessed by the disability office.'])->assertRedirect();
        $raw = DB::table('exception_requests')->value('reason');
        $this->assertStringNotContainsString('dyslexia', $raw);
        $this->assertStringContainsString('dyslexia', \App\Models\ExceptionRequest::first()->reason);
    }

    public function test_documentation_follows_roles_and_the_platform_has_its_own(): void
    {
        $student = $this->as('student', 'student');
        $this->actingAs($student)->get('/docs')->assertOk()->assertInertia(fn (Assert $p) => $p->component('docs')->where('page.slug', 'students'));
        $this->actingAs($student)->get('/docs/people')->assertNotFound();
        $lecturer = $this->as('lecturer');
        $this->actingAs($lecturer)->get('/docs/courses')->assertOk();
        $this->actingAs($lecturer)->get('/docs/billing')->assertNotFound();
        $this->actingAs($this->officer)->get('/docs/billing')->assertOk();
        $this->actingAs($this->officer)->get('/docs/getting-started?q=two-factor')->assertInertia(fn (Assert $p) => $p->where('results', fn ($r) => count($r) > 0));
        $this->actingAs($this->officer)->get('/platform/docs')->assertForbidden();
        $this->actingAs($this->platformAdmin())->get('/platform/docs/recovery')->assertOk()->assertInertia(fn (Assert $p) => $p->where('set', 'platform'));
    }

    public function test_templates_follow_permissions_and_import_cleanly(): void
    {
        $this->actingAs($this->as('student', 'student'))->get('/templates')->assertForbidden();
        $this->actingAs($this->as('lecturer'))->get('/templates/people')->assertNotFound();
        $csv = $this->actingAs($this->officer)->get('/templates/people')->assertOk()->assertHeader('content-disposition', 'attachment; filename="people-import.csv"')->getContent();
        $this->actingAs($this->officer)->post('/admin/people/import', ['file' => UploadedFile::fake()->createWithContent('people.csv', $csv)])->assertRedirect();
        $this->assertTrue(User::where('email', 'peter.okon@example.edu.ng')->exists());
        $this->assertTrue(User::where('email', 'peter.okon@example.edu.ng')->first()->hasRole('question_setter'));

        \App\Models\Department::create(['name' => 'Chemistry', 'code' => 'CHM', 'faculty_id' => \App\Models\Faculty::create(['name' => 'Science', 'code' => 'SCI'])->id]);
        $courses = $this->actingAs($this->officer)->get('/templates/courses')->getContent();
        $this->actingAs($this->officer)->post('/admin/academics/courses/import', ['file' => UploadedFile::fake()->createWithContent('courses.csv', $courses)])->assertSessionHas('success');
        $this->assertSame(5, \App\Models\Course::count());
        foreach (array_keys(\App\Support\Templates::ALL) as $key) {
            $this->actingAs($this->officer)->get("/templates/$key")->assertOk();
        }
    }
}
