<?php

namespace Tests\Feature\System;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The whole system at once, on the full demo data:
 *  - the demo seed gives every feature at least one record;
 *  - every page of the platform opens without a server error for each kind of person
 *    (platform admin, institution admin, lecturer, student), and the pages each person
 *    is meant to use open fully;
 *  - every feature an institution has not subscribed to is refused, and comes back when
 *    the platform admin switches it on.
 */
class WholeSystemTest extends TestCase
{
    use RefreshDatabase;

    /** Tables that only the framework writes (sessions, queues, caches, password resets) or one-off imports. */
    private const SYSTEM_TABLES = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
        'legacy_map', 'upload_sessions', 'model_has_permissions', 'personal_access_tokens'];

    /** Pages reached only through a secret link, a signature, a third party or a file path: not opened directly. */
    private const NOT_DIRECT = ['_inertia/', 'bridge/vuna/', 'calendar/{token}', 'email/verify', 'reset-password/', 'phone/{code}', 'support/enter/',
        'storage/{path}', 'conferencing/playback/', 'sso/{slug}', 'verify/{code}', 'up', 'sanctum/', 'logout', 'two-factor-challenge', 'login', 'register', 'forgot-password', 'apply'];

    private Tenant $institution;

    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.default_tenant' => 'veritas']);
        $this->seed(DatabaseSeeder::class);
        $this->seed(\Database\Seeders\DemoInstitutionSeeder::class); // the sample institution
        $this->institution = Tenant::where('slug', 'veritas')->firstOrFail();
        app(TenantContext::class)->set($this->institution);
        $this->seed(DemoSeeder::class);
        app(TenantContext::class)->set($this->institution);
        \Illuminate\Support\Facades\Cache::flush();
        $this->people = [
            'platform' => User::withoutGlobalScopes()->whereNull('tenant_id')->where('is_platform_admin', true)->firstOrFail(),
            'admin' => User::where('email', 'admin@veritas.local')->firstOrFail(),
            'lecturer' => User::where('email', 'lecturer@veritas.local')->firstOrFail(),
            'student' => User::where('email', 'student@veritas.local')->firstOrFail(),
        ];
        foreach ($this->people as $u) {
            // the demo administrators have two-step sign-in available; the test signs in as if it is done
            $u->forceFill(['two_factor_confirmed_at' => $u->two_factor_confirmed_at])->save();
        }
    }

    public function test_the_demo_data_covers_every_feature(): void
    {
        $empty = [];
        $own = [DB::getDatabaseName(), 'main'];
        foreach (Schema::getTableListing() as $table) {
            if (str_contains($table, '.') && ! in_array(substr($table, 0, strrpos($table, '.')), $own, true)) {
                continue; // another database on the same server
            }
            $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            if (in_array($name, self::SYSTEM_TABLES, true) || str_starts_with($name, 'sqlite_')) {
                continue;
            }
            if (DB::table($name)->count() === 0) {
                $empty[] = $name;
            }
        }

        $this->assertSame([], $empty, 'These features have no demo data: '.implode(', ', $empty));
    }

    public function test_every_page_opens_without_a_server_error_for_everyone(): void
    {
        $errors = [];
        $opened = [];
        $closed = [];
        foreach ($this->pages() as [$uri, $route]) {
            foreach ($this->people as $who => $user) {
                $platformPage = str_starts_with($uri, 'platform');
                if ($platformPage !== ($who === 'platform') && $who !== 'student') {
                    continue; // the platform console and the institution are separate worlds (checked elsewhere)
                }
                $this->app['auth']->forgetGuards();
                \Illuminate\Support\Facades\Cache::forget(\App\Http\Middleware\SingleStudentSession::key($this->people['student']->id)); // each request here is a fresh sign-in
                $this->flushSession();
                $res = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time(), 'two_factor_passed' => true])->get('/'.$uri);
                $status = $res->getStatusCode();
                if ($status >= 500) {
                    $errors[] = "$who GET /$uri → $status ".mb_substr(strip_tags((string) $res->getContent()), 0, 300);
                } elseif ($status === 200) {
                    $opened[$uri] = true;
                } else {
                    $closed[$uri][] = "$who:$status";
                }
            }
        }

        $this->assertSame([], $errors, "Pages that fail:\n".implode("\n", $errors));
        $never = array_diff_key($closed, $opened);
        if (getenv('SHOW_CLOSED')) {
            fwrite(STDERR, print_r($never, true));
        }
        $this->assertGreaterThan(110, count($opened), 'Fewer pages opened than expected: '.count($opened));
    }

    public function test_each_person_reaches_their_own_pages(): void
    {
        $must = [
            'admin' => ['dashboard', 'exams', 'exam-board', 'exam-network', 'venues', 'question-bank', 'question-bank/import', 'marking', 'proctor', 'incidents', 'meetings',
                'admin/people', 'admin/roles', 'admin/academics', 'admin/reports', 'admin/audit', 'admin/policy', 'admin/branding', 'admin/integrations',
                'admin/conferencing', 'institution/billing', 'learn/courses', 'notifications', 'messages', 'help'],
            'lecturer' => ['dashboard', 'learn/courses', 'exam-board', 'marking', 'meetings', 'question-bank', 'proctor'],
            'student' => ['dashboard', 'my/exams', 'my/results', 'learn/courses', 'meetings', 'notifications', 'help'],
            'platform' => ['platform', 'platform/institutions', 'platform/plans', 'platform/modules', 'platform/payments', 'platform/coupons', 'platform/branding',
                'platform/conferencing', 'platform/announcements', 'platform/audit', 'platform/health', 'platform/security', 'platform/admins'],
        ];
        $missing = [];
        foreach ($must as $who => $uris) {
            foreach ($uris as $uri) {
                $this->app['auth']->forgetGuards();
                \Illuminate\Support\Facades\Cache::forget(\App\Http\Middleware\SingleStudentSession::key($this->people['student']->id)); // each request here is a fresh sign-in
                $this->flushSession();
                $status = $this->actingAs($this->people[$who])->withSession(['auth.password_confirmed_at' => time()])->get('/'.$uri)->getStatusCode();
                if ($status !== 200) {
                    $missing[] = "$who /$uri → $status ".($status === 302 ? $this->actingAs($this->people[$who])->get('/'.$uri)->headers->get('Location') : '');
                }
            }
        }

        $this->assertSame([], $missing, "Pages these people should open:\n".implode("\n", $missing));
    }

    public function test_a_feature_outside_the_subscription_is_refused_until_the_platform_switches_it_on(): void
    {
        $registry = app(ModuleRegistry::class);
        $byModule = [];
        foreach ($this->pages() as [$uri, $route]) {
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'module:') && ! str_starts_with($uri, 'platform')) {
                    $byModule[substr($m, 7)][] = $uri;
                }
            }
        }
        // every feature that can be sold separately has pages that close with it
        foreach (['question_bank', 'browser_proctoring', 'cbt_halls', 'results', 'analytics', 'secure_client', 'live_proctoring', 'lms', 'conferencing', 'enterprise_hosting'] as $key) {
            $this->assertTrue($registry->exists($key));
        }
        $this->assertNotEmpty($byModule);

        $failures = [];
        foreach ($byModule as $module => $uris) {
            $uri = collect($uris)->first(fn ($u) => $this->openedFor('admin', $u) === 200);
            if (! $uri) {
                continue; // nothing in this module opens as a page (e.g. downloads only)
            }
            DB::table('tenant_modules')->updateOrInsert(['tenant_id' => $this->institution->id, 'module' => $module], ['enabled' => false, 'updated_at' => now(), 'created_at' => now()]);
            \Illuminate\Support\Facades\Cache::flush();
            if (($s = $this->openedFor('admin', $uri)) !== 403) {
                $failures[] = "$module off: /$uri → $s (expected 403)";
            }
            DB::table('tenant_modules')->where(['tenant_id' => $this->institution->id, 'module' => $module])->update(['enabled' => true]);
            \Illuminate\Support\Facades\Cache::flush();
            if (($s = $this->openedFor('admin', $uri)) !== 200) {
                $failures[] = "$module back on: /$uri → $s";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
        $this->assertGreaterThanOrEqual(8, count($byModule), 'Modules with pages: '.implode(', ', array_keys($byModule)));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function openedFor(string $who, string $uri): int
    {
        $this->app['auth']->forgetGuards();
                \Illuminate\Support\Facades\Cache::forget(\App\Http\Middleware\SingleStudentSession::key($this->people['student']->id)); // each request here is a fresh sign-in
        $this->flushSession();

        return $this->actingAs($this->people[$who])->withSession(['auth.password_confirmed_at' => time()])->get('/'.$uri)->getStatusCode();
    }

    /** @return list<array{0: string, 1: Route}> every GET page with its parameters filled from the demo data */
    private function pages(): array
    {
        $ids = $this->ids();
        $out = [];
        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || str_starts_with($uri, 'api/')) {
                continue;
            }
            if (collect(self::NOT_DIRECT)->contains(fn ($p) => $uri === $p || str_starts_with($uri, $p)) || in_array('signed', $route->gatherMiddleware(), true)) {
                continue;
            }
            if (str_contains($uri, '/export') || str_ends_with($uri, '/pdf') || str_contains($uri, '/file') || str_contains($uri, '.ics') || str_contains($uri, '.css')) {
                // downloads are covered by their own feature tests; still make sure they do not crash
            }
            $filled = preg_replace_callback('/\{(\w+)(\?)?\}/', fn ($m) => (string) ($ids[$m[1]] ?? (! empty($m[2]) ? '' : '__missing__')), $uri);
            if (str_contains($filled, '__missing__')) {
                continue;
            }
            $out[] = [rtrim($filled, '/'), $route];
        }

        return $out;
    }

    /** A real id from the demo data for every route parameter. */
    private function ids(): array
    {
        $t = $this->institution->id;
        $first = fn (string $table, array $where = []) => DB::table($table)->where($where)->orderBy('id')->value('id');
        $exam = DB::table('exams')->where('tenant_id', $t)->whereIn('type', ['mixed', 'cbt'])->orderBy('id')->value('id');
        $offering = DB::table('course_offerings')->where('tenant_id', $t)->orderBy('id')->value('id');
        $meeting = DB::table('meetings')->where('tenant_id', $t)->orderBy('id')->value('uuid');
        $import = DB::table('question_imports')->where('tenant_id', $t)->orderBy('id')->value('id');
        $session = DB::table('exam_sessions')->where('tenant_id', $t)->where('exam_id', $exam)->orderBy('id')->value('id');
        $student = $this->people['student']->id;

        return [
            'exam' => $exam, 'session' => $session, 'offering' => $offering, 'meeting' => $meeting, 'import' => $import,
            'candidate' => DB::table('exam_candidates')->where('user_id', $student)->orderBy('id')->value('id'),
            'attempt' => $first('attempts', ['tenant_id' => $t]), 'question' => $first('questions', ['tenant_id' => $t]),
            'incident' => $first('incidents', ['tenant_id' => $t]), 'item' => $first('course_items', ['tenant_id' => $t]),
            'thread' => $first('forum_threads', ['tenant_id' => $t]), 'submission' => $first('assignment_submissions'),
            'ticket' => $first('support_tickets', ['tenant_id' => $t]), 'exception' => $first('exception_requests', ['tenant_id' => $t]),
            'invoice' => $first('invoices', ['tenant_id' => $t]), 'tenant' => $t, 'student' => $student,
            'evidence' => $first('evidence_files', ['tenant_id' => $t]), 'recording' => $first('media_recordings', ['tenant_id' => $t]),
            'profile' => $first('face_profiles', ['tenant_id' => $t]), 'which' => 'id', 'report' => 'students', 'format' => 'csv', 'key' => 'questions_text',
            'slug' => null, 'id' => null,
        ];
    }
}
