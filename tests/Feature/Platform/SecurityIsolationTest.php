<?php

namespace Tests\Feature\Platform;

use App\Models\Exam;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Question;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Billing\Billing;
use App\Services\Billing\Entitlements;
use App\Services\Questions\RichTextSanitizer;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/**
 * Security regression suite: institution isolation (IDOR across institutions, tenant from the browser
 * ignored, caches kept apart), privilege escalation, authentication bypass, injection, XSS, CSRF,
 * mass assignment and security headers. Each test attacks; none relies on hidden buttons.
 */
class SecurityIsolationTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private Tenant $other;

    private array $theirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        // a second institution with its own records
        $this->other = app(TenantProvisioner::class)->provision(['slug' => 'rival', 'name' => 'Rival University', 'domains' => ['rival.test']], 'enterprise');
        $this->other->update(['plan_id' => Plan::where('key', 'enterprise')->value('id')]);
        $me = $this->officer;
        app(TenantContext::class)->run($this->other, function () use ($me) {
            $boss = User::factory()->create(['user_type' => 'staff', 'name' => 'Rival Admin']);
            $boss->assignRole('institution_admin');
            $this->officer = $boss;
            $exam = $this->cbtExam(['title' => 'Rival secret exam']);
            $this->theirs = ['user' => $boss->id, 'exam' => $exam->id, 'question' => Question::query()->value('id'), 'faculty' => Faculty::create(['name' => 'Rival Sci', 'code' => 'RS'])->id];
            $boss->notify(new LmsNotice('Rival private', 'x'));
            $this->theirs['notification'] = $boss->notifications()->value('id');
        });
        $this->officer = $me;
        $sub = app(Billing::class)->start($this->other, Plan::where('key', 'starter')->first(), 'yearly', null, 0);
        $this->theirs['invoice'] = Invoice::where('subscription_id', $sub->id)->value('id');
        app(TenantContext::class)->set($this->tenant);
    }

    public function test_no_record_of_another_institution_opens_by_id(): void
    {
        $t = $this->theirs;
        $this->actingAs($this->officer);
        $this->get("/exams/{$t['exam']}")->assertNotFound();
        $this->put("/exams/{$t['exam']}", ['title' => 'hacked'])->assertNotFound();
        $this->get("/question-bank/questions/{$t['question']}/edit")->assertNotFound();
        $this->put("/admin/people/{$t['user']}", ['name' => 'hacked', 'email' => 'x@y.z', 'user_type' => 'staff', 'status' => 'inactive'])->assertNotFound();
        $this->post("/notifications/{$t['notification']}/read")->assertNotFound();
        $this->get("/institution/billing/invoices/{$t['invoice']}/pdf")->assertNotFound();
        $this->post("/institution/billing/invoices/{$t['invoice']}/pay")->assertNotFound();
        app(TenantContext::class)->run($this->other, function () use ($t) {
            $this->assertSame('Rival secret exam', Exam::find($t['exam'])->title);
            $this->assertSame('active', User::find($t['user'])->status ?? 'active');
        });
    }

    public function test_lists_searches_and_exports_never_include_another_institution(): void
    {
        $this->actingAs($this->officer);
        $this->get('/exams')->assertOk()->assertDontSee('Rival secret exam');
        $this->get('/admin/people?q=Rival')->assertOk()->assertDontSee('Rival Admin');
        $this->get('/admin/academics')->assertOk()->assertDontSee('Rival Sci');
        $this->get('/admin/audit/export')->assertOk();
        $this->assertSame(0, Exam::where('title', 'Rival secret exam')->count()); // the scope applies to every query
    }

    public function test_the_institution_is_decided_by_the_server_not_the_browser(): void
    {
        // a tenant_id sent by the browser is ignored
        $this->actingAs($this->officer)->post('/admin/academics/units/faculty', ['name' => 'Arts', 'code' => 'ART', 'tenant_id' => $this->other->id]);
        $this->assertSame($this->tenant->id, Faculty::where('code', 'ART')->value('tenant_id'));
        // asking for another institution by header or by its address while signed in here is refused
        $this->actingAs($this->officer)->withHeader('Host', 'rival.test')->get('http://rival.test/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_cached_entitlements_and_branding_are_per_institution(): void
    {
        Plan::where('key', 'enterprise')->update(['limits' => json_encode(['students' => 5])]);
        $this->other->update(['plan_id' => Plan::where('key', 'starter')->value('id')]);
        Entitlements::forget($this->tenant->id);
        Entitlements::forget($this->other->id);
        $e = app(Entitlements::class);
        $this->assertSame(5, $e->limit('students', $this->tenant->fresh()));
        $this->assertSame(3000, $e->limit('students', $this->other->fresh()));
        $this->other->update(['brand_color' => '#aa0000']);
        $this->get('http://rival.test/login')->assertSee('--brand: #aa0000', false);
        $this->flushSession();
        $this->get(config('app.url').'/login')->assertDontSee('#aa0000', false)->assertSee(e(\App\Support\Platform\PlatformSettings::brand()['short_name']).'</title>', false); // the platform's own address shows the platform
    }

    public function test_people_cannot_raise_their_own_privileges(): void
    {
        $lecturer = User::factory()->create(['user_type' => 'staff']);
        $lecturer->assignRole('lecturer');
        $adminRole = \DB::table('roles')->where('tenant_id', $this->tenant->id)->where('name', 'institution_admin')->value('id');
        $this->actingAs($lecturer)->put("/admin/people/{$lecturer->id}", ['name' => $lecturer->name, 'email' => $lecturer->email, 'user_type' => 'staff', 'roles' => ['institution_admin']])->assertForbidden();
        $this->actingAs($lecturer)->put("/admin/roles/$adminRole", ['label' => 'x', 'permissions' => ['users.manage']])->assertForbidden();
        $this->actingAs($lecturer)->get('/institution/billing')->assertForbidden();
        $this->actingAs($lecturer)->get('/platform')->assertForbidden();
        // profile updates cannot set protected columns
        $this->actingAs($lecturer)->patch('/settings/profile', ['name' => 'L', 'email' => $lecturer->email, 'is_platform_admin' => true, 'tenant_id' => $this->other->id, 'user_type' => 'platform']);
        $fresh = $lecturer->fresh();
        $this->assertFalse((bool) $fresh->is_platform_admin);
        $this->assertSame($this->tenant->id, $fresh->tenant_id);
        $this->assertSame('staff', $fresh->user_type);
        $this->assertFalse($fresh->hasRole('institution_admin'));
    }

    public function test_protected_pages_and_apis_need_sign_in(): void
    {
        foreach (['/dashboard', '/exams', '/admin/people', '/admin/audit', '/institution/billing', '/notifications', '/learn/courses', '/platform'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->getJson('/api/v1/results')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer vk_fakefake_'.str_repeat('x', 40))->getJson('/api/v1/results')->assertUnauthorized();
        $this->postJson('/api/exam/attempt/heartbeat')->assertUnauthorized();
    }

    public function test_injection_in_search_and_filters_is_harmless(): void
    {
        $this->actingAs($this->officer);
        foreach (["' OR '1'='1", '1; DROP TABLE users; --', '%', '_', "\\'"] as $evil) {
            $this->get('/admin/people?q='.urlencode($evil))->assertOk()->assertDontSee('Rival Admin');
            $this->get('/admin/audit?q='.urlencode($evil).'&actor='.urlencode($evil))->assertOk();
            $this->get('/notifications?category='.urlencode($evil))->assertOk();
        }
        $this->assertGreaterThan(0, User::withoutGlobalScopes()->count()); // nothing dropped
        $this->get('/admin/audit?from=not-a-date')->assertSessionHasErrors('from');
    }

    public function test_stored_html_is_cleaned_of_scripts(): void
    {
        $clean = app(RichTextSanitizer::class)->clean('<p onclick="steal()">Hi<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a></p>');
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('Hi', $clean);
    }

    public function test_forms_are_protected_against_cross_site_requests(): void
    {
        $middleware = new class(app(), app('encrypter')) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
        $request = Request::create('/admin/academics/units/faculty', 'POST', ['name' => 'x']);
        $request->setLaravelSession(app('session')->driver());
        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => response('ok'));
    }

    public function test_csrf_is_in_the_web_stack_and_security_headers_are_sent(): void
    {
        $this->assertContains(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, app(\Illuminate\Foundation\Http\Kernel::class)->getMiddlewareGroups()['web']);
        $r = $this->get('/login');
        $this->assertNotEmpty($r->headers->get('Content-Security-Policy'));
        $this->assertSame('DENY', $r->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertNotNull($this->get('https://localhost/login')->headers->get('Strict-Transport-Security'));
    }

    public function test_sign_in_is_throttled_against_guessing(): void
    {
        $u = User::factory()->create(['email' => 'target@vu.edu.ng']);
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => 'target@vu.edu.ng', 'password' => 'wrong-'.$i]);
        }
        $this->post('/login', ['email' => 'target@vu.edu.ng', 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }
}
