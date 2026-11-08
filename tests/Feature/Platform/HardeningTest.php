<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\PlatformAnnouncement;
use App\Models\SecurityEvent;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Platform\PlatformSettings;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Notification centre, announcements, maintenance, audited support sign-in, signing people out when they
 * lose power, upload screening, request limits, audit search/export and white-label branding.
 */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        app(TenantProvisioner::class)->provision(['slug' => $this->tenant->slug, 'name' => $this->tenant->name], 'enterprise');
        $this->tenant->update(['plan_id' => \App\Models\Plan::where('key', 'enterprise')->value('id')]);
        $this->admin = User::factory()->create(['user_type' => 'staff']);
        $this->admin->assignRole('institution_admin');
        $this->student = User::factory()->create(['user_type' => 'student']);
        $this->student->assignRole('student');
        $this->platform = User::factory()->create();
        $this->platform->forceFill(['tenant_id' => null, 'is_platform_admin' => true, 'user_type' => 'platform', 'two_factor_confirmed_at' => now()])->save();
    }

    // ---------- notification centre ----------

    public function test_notification_centre_marks_read_marks_all_deletes_and_filters_by_kind(): void
    {
        $this->student->notify(new LmsNotice('New material', 'Week 3 notes', '/learn/courses', false));
        $this->student->notify(new LmsNotice('Invoice', 'Pay', null, false, category: 'billing'));
        $this->student->notify(new LmsNotice('Exam', 'Friday', null, false, category: 'exams'));
        $this->actingAs($this->student)->get('/notifications')->assertOk()->assertInertia(fn (Assert $p) => $p->component('learn/notifications')
            ->where('unread', 3)->has('notifications.data', 3)->where('notificationsUnread', 3));
        // listing does not mark anything read
        $this->assertSame(3, $this->student->unreadNotifications()->count());
        $this->actingAs($this->student)->get('/notifications?category=exams')->assertInertia(fn (Assert $p) => $p->has('notifications.data', 1)->where('notifications.data.0.data.title', 'Exam'));

        $id = $this->student->notifications()->where('data->title', 'New material')->value('id');
        $this->actingAs($this->student)->post("/notifications/$id/read", ['open' => true])->assertRedirect('/learn/courses');
        $this->assertSame(2, $this->student->unreadNotifications()->count());
        $this->actingAs($this->student)->post('/notifications/read-all')->assertSessionHas('success');
        $this->assertSame(0, $this->student->unreadNotifications()->count());
        $this->actingAs($this->student)->delete("/notifications/$id")->assertRedirect();
        $this->assertSame(2, $this->student->notifications()->count());
        $this->actingAs($this->student)->delete('/notifications')->assertSessionHas('success');
        $this->assertSame(0, $this->student->notifications()->count());
    }

    public function test_nobody_can_read_or_delete_another_persons_notification(): void
    {
        $this->admin->notify(new LmsNotice('Private', 'For the admin'));
        $id = $this->admin->notifications()->value('id');
        $this->actingAs($this->student)->post("/notifications/$id/read")->assertNotFound();
        $this->actingAs($this->student)->delete("/notifications/$id")->assertNotFound();
        $this->assertSame(1, $this->admin->unreadNotifications()->count());
    }

    public function test_preferences_turn_off_kinds_but_never_security_alerts(): void
    {
        config(['mail.default' => 'smtp']);
        $this->actingAs($this->student)->put('/settings/notifications', ['prefs' => [
            'course' => ['app' => false, 'mail' => false], 'security' => ['app' => false, 'mail' => false], 'billing' => ['app' => true, 'mail' => false],
        ]])->assertSessionHas('success');
        $s = $this->student->fresh();
        $this->assertSame([], (new LmsNotice('a', 'b'))->via($s));
        $this->assertSame(['database'], (new LmsNotice('a', 'b', category: 'billing'))->via($s));
        $this->assertSame(['database', 'mail'], (new LmsNotice('a', 'b', category: 'security'))->via($s));
        $this->actingAs($s)->get('/settings/notifications')->assertOk()->assertInertia(fn (Assert $p) => $p->component('settings/notifications')
            ->where('categories.8.key', 'security')->where('categories.8.mandatory', true)->where('categories.8.app', true));
    }

    // ---------- announcements and maintenance ----------

    public function test_announcements_reach_the_chosen_audience_and_can_be_dismissed(): void
    {
        $other = app(TenantProvisioner::class)->provision(['slug' => 'other', 'name' => 'Other'], 'starter');
        $this->actingAs($this->platform)->post('/platform/announcements', ['title' => 'Upgrade', 'body' => 'Saturday 2am', 'level' => 'warning', 'audience' => 'students',
            'tenant_ids' => [$this->tenant->id], 'dismissible' => true])->assertSessionHas('success');
        $this->actingAs($this->platform)->post('/platform/announcements', ['title' => 'Elsewhere', 'body' => 'Only there', 'level' => 'info', 'audience' => 'everyone',
            'tenant_ids' => [$other->id], 'dismissible' => false]);
        $this->actingAs($this->student)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('announcements', 1)->where('announcements.0.title', 'Upgrade'));
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('announcements', 0)); // staff are not the audience
        $id = PlatformAnnouncement::where('title', 'Upgrade')->value('id');
        $this->actingAs($this->student)->post("/announcements/$id/dismiss")->assertRedirect();
        $this->actingAs($this->student)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('announcements', 0));
        // a notice that cannot be dismissed stays
        $this->actingAs($this->student)->post('/announcements/'.PlatformAnnouncement::where('title', 'Elsewhere')->value('id').'/dismiss')->assertNotFound();
        $this->actingAs($this->admin)->post('/platform/announcements', ['title' => 'x', 'body' => 'y', 'level' => 'info', 'audience' => 'everyone'])->assertForbidden();
    }

    public function test_maintenance_mode_stops_institutions_but_not_the_platform_team_or_sign_in(): void
    {
        $this->actingAs($this->platform)->put('/platform/maintenance', ['maintenance_mode' => true, 'maintenance_message' => 'Back at 3am'])->assertSessionHas('success');
        $this->actingAs($this->student)->get('/dashboard')->assertStatus(503)->assertInertia(fn (Assert $p) => $p->component('maintenance')->where('message', 'Back at 3am'));
        $this->actingAs($this->student)->getJson('/notifications')->assertStatus(503)->assertJson(['error' => 'maintenance']);
        $this->actingAs($this->platform)->get('/platform')->assertOk();
        auth()->logout();
        $this->get('/login')->assertOk();
        $this->actingAs($this->platform)->put('/platform/maintenance', ['maintenance_mode' => false]);
        $this->actingAs($this->student)->get('/dashboard')->assertOk();
    }

    // ---------- audited support sign-in ----------

    private function supportLink(): string
    {
        $r = $this->actingAs($this->platform)->withHeader('X-Inertia', 'true')->post("/platform/institutions/{$this->tenant->id}/support", ['reason' => 'Ticket 881: course page error'])
            ->assertStatus(409);

        return parse_url($r->headers->get('X-Inertia-Location'), PHP_URL_PATH);
    }

    public function test_support_sign_in_is_recorded_announced_limited_and_ends(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $link = $this->supportLink();
        $this->flushHeaders();
        $this->actingAs($this->platform)->get($link)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->admin);
        $s = SupportSession::sole();
        $this->assertNotNull($s->started_at);
        \Illuminate\Support\Facades\Notification::assertSentTo($this->admin, LmsNotice::class, fn ($n) => $n->category === 'security');

        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->where('impersonation.by', $this->platform->name));
        // secrets and money stay with the account holder
        $this->put('/settings/password', ['current_password' => 'password', 'password' => 'N3w-passw0rd!!', 'password_confirmation' => 'N3w-passw0rd!!'])->assertForbidden();
        $this->post('/institution/billing/cancel')->assertForbidden();
        // what is done is marked as support, in the institution's own trail
        app(AuditLogger::class)->record('test.action');
        $this->assertSame($s->id, AuditLog::where('action', 'test.action')->value('data')['support_session']);
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant->id)->where('action', 'support.session_started')->exists());
        $this->assertTrue(AuditLog::whereNull('tenant_id')->where('action', 'platform.support_session_requested')->exists());

        $this->post('/support/leave')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNotNull($s->fresh()->ended_at);
    }

    public function test_a_support_link_is_single_use_personal_and_expires(): void
    {
        $link = $this->supportLink();
        $this->flushHeaders();
        $other = User::factory()->create();
        $other->forceFill(['tenant_id' => null, 'is_platform_admin' => true, 'user_type' => 'platform', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($other)->get($link)->assertForbidden();        // another platform administrator
        $this->actingAs($this->platform)->get($link)->assertForbidden(); // already consumed by the attempt above

        $link = $this->supportLink();
        $this->flushHeaders();
        $this->actingAs($this->platform)->get($link)->assertRedirect('/dashboard');
        $this->travel(31)->minutes();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_institution_people_cannot_start_support_sessions(): void
    {
        $this->actingAs($this->admin)->post("/platform/institutions/{$this->tenant->id}/support", ['reason' => 'Let me in please'])->assertForbidden();
        $this->assertSame(0, SupportSession::count());
    }

    // ---------- losing power signs out everywhere ----------

    public function test_removing_an_administrator_role_signs_the_person_out_everywhere(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertOk();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => time()]);
        $this->admin->removeRole('institution_admin');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->admin->id)->count());
        $this->actingAs($this->admin->fresh())->get('/dashboard')->assertRedirect('/login');
        $this->assertTrue(AuditLog::where('action', 'security.sessions_ended')->exists());
    }

    public function test_a_role_losing_a_privileged_permission_signs_its_members_out(): void
    {
        $ict = User::factory()->create(['user_type' => 'staff']);
        $ict->assignRole('ict_admin');
        $roleId = DB::table('roles')->where('name', 'ict_admin')->where('tenant_id', $this->tenant->id)->value('id');
        $this->actingAs($this->admin)->put("/admin/roles/$roleId", ['label' => 'ICT', 'description' => null, 'permissions' => ['audit.view']])->assertSessionHas('success');
        $this->assertNotNull(Cache::get(\App\Services\Security\SessionTerminator::cutoffKey($ict->id)));
        $this->assertNull(Cache::get(\App\Services\Security\SessionTerminator::cutoffKey($this->admin->id)));
        $this->actingAs($ict->fresh())->get('/dashboard')->assertRedirect('/login');
    }

    public function test_switching_an_account_off_signs_it_out(): void
    {
        $this->student->update(['status' => 'inactive']);
        $this->assertNotNull(Cache::get(\App\Services\Security\SessionTerminator::cutoffKey($this->student->id)));
    }

    // ---------- uploads ----------

    public function test_programs_disguised_files_and_known_malware_are_refused(): void
    {
        $cases = [
            UploadedFile::fake()->createWithContent('setup.exe', 'anything'),
            UploadedFile::fake()->createWithContent('notes.pdf', "MZ\x90\x00 this is a windows program"),
            UploadedFile::fake()->createWithContent('photo.php.jpg', 'x'),
            UploadedFile::fake()->createWithContent('test.txt', 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'),
            UploadedFile::fake()->createWithContent('shell.txt', '<?php system($_GET["c"]);'),
        ];
        foreach ($cases as $file) {
            $this->actingAs($this->admin)->from('/admin/branding')->post('/admin/branding', ['name' => 'X', 'brand_color' => '#112233', 'logo' => $file])
                ->assertRedirect('/admin/branding')->assertSessionHasErrors('logo');
        }
        $this->assertSame(5, SecurityEvent::where('type', 'upload_blocked')->count());
    }

    public function test_an_unreachable_antivirus_refuses_uploads_when_configured_to(): void
    {
        config(['security.uploads.clamav' => 'tcp://127.0.0.1:1', 'security.uploads.fail_closed' => true]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $f = UploadedFile::fake()->createWithContent('ok.txt', 'hello');
        app(\App\Services\Security\UploadScanner::class)->check($f->getRealPath(), 'ok.txt');
    }

    // ---------- request limits ----------

    public function test_request_limits_apply_per_person_and_per_institution(): void
    {
        PlatformSettings::put(['rate_user_per_minute' => 3]);
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->student)->get('/notifications')->assertOk();
        }
        $this->actingAs($this->student)->get('/notifications')->assertStatus(429);
        $this->actingAs($this->admin)->get('/notifications')->assertOk(); // someone else is not affected

        PlatformSettings::put(['rate_user_per_minute' => 0, 'rate_tenant_per_minute' => 5]);
        \Illuminate\Support\Facades\RateLimiter::clear('rl:t:'.$this->tenant->id);
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->admin)->get('/notifications');
        }
        $this->actingAs($this->admin)->getJson('/notifications')->assertStatus(429)->assertJson(['error' => 'rate_limited']);
    }

    // ---------- audit ----------

    public function test_audit_search_and_export_show_only_the_institutions_own_trail(): void
    {
        app(AuditLogger::class)->record('exam.published', null, ['title' => '=HYPERLINK("x")']);
        $other = app(TenantProvisioner::class)->provision(['slug' => 'other', 'name' => 'Other'], 'starter');
        app(TenantContext::class)->run($other, fn () => app(AuditLogger::class)->record('exam.published', null, ['title' => 'theirs']));
        app(TenantContext::class)->set($this->tenant);

        $this->actingAs($this->admin)->get('/admin/audit?q=published')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/audit')->where('logs.total', 1));
        $this->actingAs($this->admin)->get('/admin/audit?verify=1')->assertInertia(fn (Assert $p) => $p->where('broken', 0));
        $csv = $this->actingAs($this->admin)->get('/admin/audit/export?q=published')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('theirs', $csv);
        $this->assertStringContainsString('exam.published', $csv);
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant->id)->where('action', 'audit.exported')->exists());
        $this->actingAs($this->student)->get('/admin/audit')->assertForbidden();

        // the platform can open one institution's trail, and that is recorded in it
        $this->actingAs($this->platform)->get("/platform/audit?tenant={$other->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('logs.data.0.action', 'platform.audit_viewed'));
        $this->assertTrue(AuditLog::where('action', 'platform.audit_viewed')->exists());
    }

    // ---------- branding ----------

    public function test_branding_covers_colours_sign_in_words_welcome_and_contact(): void
    {
        $this->actingAs($this->admin)->post('/admin/branding', ['name' => 'Veritas University', 'brand_color' => '#123456', 'secondary_color' => '#ff8800',
            'login_title' => 'e-Learning portal', 'login_message' => 'Use your matric number', 'welcome_message' => 'Exams start Monday.', 'official_email' => 'ict@vu.edu.ng',
            'favicon' => UploadedFile::fake()->image('icon.png', 32, 32)])->assertSessionHas('success');
        $b = $this->tenant->fresh()->brandPayload();
        $this->assertSame('#ff8800', $b['accent']);
        $this->assertNotNull($b['favicon_url']);
        $this->assertSame(['email' => 'ict@vu.edu.ng'], $b['contact']);
        auth()->logout();
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $p) => $p->where('tenant.login_title', 'e-Learning portal'))->assertSee('--brand-2: #ff8800', false);
        $this->actingAs($this->student)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('tenant.welcome_message', 'Exams start Monday.'));
        // scripts in the words are stripped
        $this->actingAs($this->admin)->post('/admin/branding', ['name' => 'V', 'brand_color' => '#123456', 'welcome_message' => '<script>alert(1)</script>Hi']);
        $this->assertSame('alert(1)Hi', $this->tenant->fresh()->brandPayload()['welcome_message']);
        $this->actingAs($this->admin)->post('/admin/branding', ['name' => 'V', 'brand_color' => 'red'])->assertSessionHasErrors('brand_color');
    }

    public function test_an_oversized_logo_is_compressed_instead_of_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->post('/admin/branding', [
            'name' => 'Veritas University', 'brand_color' => '#123456',
            'logo' => UploadedFile::fake()->image('huge-logo.png', 3000, 2000),
        ])->assertSessionHasNoErrors();

        $path = $this->tenant->fresh()->logo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        [$width, $height] = getimagesize(Storage::disk('public')->path($path));
        $this->assertLessThanOrEqual(1024, $width);
        $this->assertLessThanOrEqual(1024, $height);
    }

    // ---------- reported faults ----------

    public function test_a_platform_administrator_with_two_factor_can_finish_signing_in_at_an_institution_address(): void
    {
        $g = new \PragmaRX\Google2FA\Google2FA;
        $secret = $g->generateSecretKey();
        $this->platform->forceFill(['password' => 'Platform-pass-123', 'two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt(json_encode(['r1']))])->save();
        $this->post('/login', ['email' => $this->platform->email, 'password' => 'Platform-pass-123'])->assertRedirect('/two-factor-challenge');
        $this->get('/two-factor-challenge')->assertOk(); // was sent back to /login: the institution-only scope hid the account
        $this->post('/two-factor-challenge', ['code' => $g->getCurrentOtp($secret)])->assertRedirect('/platform');
        $this->assertAuthenticatedAs($this->platform);
    }

    public function test_halls_and_devices_page_loads_with_device_venues(): void
    {
        $v = Venue::create(['name' => 'Hall A', 'capacity' => 10]);
        Device::create(['venue_id' => $v->id, 'label' => 'PC-01', 'fingerprint_hash' => str_repeat('a', 64), 'status' => 'active']);
        $this->actingAs($this->admin)->get('/venues')->assertOk()->assertInertia(fn (Assert $p) => $p->where('devices.0.venue.name', 'Hall A'));
    }
}
