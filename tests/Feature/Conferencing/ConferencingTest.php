<?php

namespace Tests\Feature\Conferencing;

use App\Conferencing\Attendance;
use App\Models\AcademicSession;
use App\Models\ConferenceServer;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingRecording;
use App\Models\Semester;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Platform\PlatformSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/**
 * Live classes on BigBlueButton, tested against recorded copies of the BigBlueButton API's replies
 * (no real meeting server is reachable from the test run). What is checked is our side: who may do
 * what, the signed calls we make, webhook verification, attendance from events, and recording access.
 */
class ConferencingTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private const SECRET = 'test-shared-secret-0123456789abcdef';

    private User $lecturer;

    private CourseOffering $offering;

    private User $student;

    private ConferenceServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $semester = Semester::create(['academic_session_id' => $session->id, 'name' => 'First semester', 'is_current' => true, 'starts_on' => '2026-10-01']);
        $this->lecturer = User::factory()->create(['user_type' => 'staff', 'name' => 'Dr Ada Obi']);
        $this->lecturer->assignRole('lecturer');
        $course = Course::create(['code' => 'CHM 101', 'title' => 'General Chemistry', 'credit_units' => 3, 'level' => 100]);
        $this->offering = CourseOffering::create(['course_id' => $course->id, 'semester_id' => $semester->id, 'status' => 'open']);
        $this->offering->staff()->attach($this->lecturer->id, ['role' => 'coordinator']);
        $this->student = $this->student();
        Enrolment::create(['course_offering_id' => $this->offering->id, 'user_id' => $this->student->id, 'status' => 'active']);
        $this->server = ConferenceServer::create(['tenant_id' => null, 'name' => 'Shared 1', 'base_url' => 'https://bbb.test/bigbluebutton/', 'secret' => self::SECRET, 'checksum_algo' => 'sha256', 'status' => 'online']);
    }

    private function student(): User
    {
        $u = User::factory()->create(['user_type' => 'student', 'identifier' => 'VUG/'.fake()->unique()->numerify('#####')]);
        $u->assignRole('student');

        return $u;
    }

    private function fakeBbb(array $extra = []): void
    {
        Http::fake($extra + [
            'bbb.test/bigbluebutton/api/create*' => Http::response('<response><returncode>SUCCESS</returncode><meetingID>x</meetingID><createTime>1762400000000</createTime></response>'),
            'bbb.test/bigbluebutton/api/end*' => Http::response('<response><returncode>SUCCESS</returncode><messageKey>sentEndMeetingRequest</messageKey></response>'),
            'bbb.test/bigbluebutton/api/getMeetings*' => Http::response('<response><returncode>SUCCESS</returncode><meetings><meeting><participantCount>12</participantCount></meeting></meetings></response>'),
            'bbb.test/bigbluebutton/api/hooks/create*' => Http::response('<response><returncode>SUCCESS</returncode><hookID>1</hookID></response>'),
            'bbb.test/bigbluebutton/api' => Http::response('<response><returncode>SUCCESS</returncode><version>3.0</version></response>'),
        ]);
    }

    private function schedule(array $over = []): Meeting
    {
        $this->actingAs($this->lecturer)->post('/meetings', array_replace_recursive([
            'title' => 'CHM 101 live class', 'type' => 'class', 'course_offering_id' => $this->offering->id, 'starts_at' => now()->addMinutes(5)->format('Y-m-d H:i'),
            'duration_minutes' => 60, 'timezone' => 'UTC', 'access' => 'course', 'settings' => ['record' => true],
        ], $over))->assertSessionHasNoErrors()->assertRedirect();

        return Meeting::latest('id')->firstOrFail();
    }

    /** Recompute BigBlueButton's checksum for a call we made. */
    private function assertSigned(string $url, string $call): void
    {
        [$base, $query] = explode('?', $url, 2);
        $this->assertStringEndsWith('/api/'.$call, $base);
        parse_str($query, $q);
        $without = preg_replace('/&?checksum=[0-9a-f]+$/', '', $query);
        $this->assertSame(hash('sha256', $call.$without.self::SECRET), $q['checksum']);
    }

    // ---------- scheduling and access ----------

    public function test_a_lecturer_schedules_a_class_that_enrolled_students_can_see_and_others_cannot(): void
    {
        $m = $this->schedule();
        $this->assertSame('course', $m->access);
        $this->assertSame('host', $m->participants()->where('user_id', $this->lecturer->id)->value('role'));
        $this->actingAs($this->student)->get('/meetings')->assertOk()->assertInertia(fn (Assert $p) => $p->component('meetings/index')->where('upcoming.0.title', 'CHM 101 live class'));
        $this->actingAs($this->student)->get("/meetings/{$m->uuid}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.moderate', false)->where('participants', []));
        $outsider = $this->student();
        $this->actingAs($outsider)->get("/meetings/{$m->uuid}")->assertForbidden();
        $this->actingAs($outsider)->post("/meetings/{$m->uuid}/join")->assertForbidden();
        // a student cannot schedule, and a lecturer cannot schedule for someone else's course
        $this->actingAs($this->student)->get('/meetings/create')->assertForbidden();
        $other = CourseOffering::create(['course_id' => Course::create(['code' => 'PHY 101', 'title' => 'Physics', 'credit_units' => 3, 'level' => 100])->id, 'semester_id' => $this->offering->semester_id, 'status' => 'open']);
        $this->actingAs($this->lecturer)->post('/meetings', ['title' => 'x', 'type' => 'class', 'course_offering_id' => $other->id, 'starts_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 60, 'timezone' => 'UTC', 'access' => 'course'])->assertForbidden();
    }

    public function test_another_institutions_meetings_are_not_found(): void
    {
        $m = $this->schedule();
        $rival = app(TenantProvisioner::class)->provision(['slug' => 'rival', 'name' => 'Rival'], 'enterprise');
        app(TenantContext::class)->run($rival, function () use ($m) {
            $boss = User::factory()->create(['user_type' => 'staff']);
            $boss->assignRole('institution_admin');
            $this->assertNull(Meeting::where('uuid', $m->uuid)->first());
        });
        $this->assertNotNull(Meeting::where('uuid', $m->uuid)->first());
    }

    public function test_a_weekly_series_makes_one_meeting_per_class_day(): void
    {
        $start = now()->next('Monday')->setTime(10, 0);
        $this->schedule(['starts_at' => $start->format('Y-m-d H:i'), 'recurrence' => ['days' => [1, 3], 'until' => $start->copy()->addWeeks(2)->addDays(3)->toDateString()]]);
        $this->assertSame(6, Meeting::count()); // Mon, Wed × 3 weeks
        $first = Meeting::orderBy('starts_at')->first();
        $this->assertSame(5, Meeting::where('series_id', $first->id)->count());
        $this->actingAs($this->lecturer)->post("/meetings/{$first->uuid}/cancel", ['series' => true])->assertRedirect('/meetings');
        $this->assertSame(6, Meeting::where('status', 'cancelled')->count());
    }

    // ---------- joining ----------

    public function test_the_host_starts_the_room_with_a_signed_call_and_students_join_as_viewers(): void
    {
        $this->fakeBbb();
        $m = $this->schedule(['settings' => ['waiting_room' => true]]);

        // before the host starts it, students wait
        $this->actingAs($this->student)->withHeader('X-Inertia', 'true')->post("/meetings/{$m->uuid}/join")->assertSessionHas('error');

        $r = $this->actingAs($this->lecturer)->withHeader('X-Inertia', 'true')->post("/meetings/{$m->uuid}/join")->assertStatus(409);
        $join = $r->headers->get('X-Inertia-Location');
        $this->assertSigned($join, 'join');
        $this->assertStringContainsString('role=MODERATOR', $join);
        $this->assertStringContainsString('userID=u'.$this->lecturer->id, $join);
        $this->assertStringNotContainsString(self::SECRET, $join);
        Http::assertSent(function ($req) use ($m) {
            if (! str_contains($req->url(), '/api/create')) {
                return false;
            }
            $this->assertSigned($req->url(), 'create');

            return str_contains($req->url(), 'meetingID='.$m->bbb_meeting_id) && str_contains($req->url(), 'guestPolicy=ASK_MODERATOR') && str_contains($req->url(), 'record=true');
        });
        $this->assertSame('live', $m->fresh()->status);

        $r = $this->flushHeaders()->actingAs($this->student)->withHeader('X-Inertia', 'true')->post("/meetings/{$m->uuid}/join")->assertStatus(409);
        $url = $r->headers->get('X-Inertia-Location');
        $this->assertStringContainsString('role=VIEWER', $url);
        $this->assertStringContainsString('guest=true', $url); // the waiting room
        // the page never carries the secret or the room passwords
        $page = $this->flushHeaders()->actingAs($this->lecturer)->get("/meetings/{$m->uuid}")->getContent();
        $this->assertStringNotContainsString(self::SECRET, $page);
        $this->assertStringNotContainsString((string) $m->moderator_pw, $page);
    }

    public function test_the_room_does_not_open_long_before_its_time(): void
    {
        $this->fakeBbb();
        $m = $this->schedule(['starts_at' => now()->addDays(2)->format('Y-m-d H:i')]);
        $this->actingAs($this->lecturer)->withHeader('X-Inertia', 'true')->post("/meetings/{$m->uuid}/join")->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_without_any_server_joining_explains_what_is_missing(): void
    {
        $this->server->delete();
        // automatic: with no meeting server the built-in room opens instead
        $m = $this->schedule();
        $this->actingAs($this->lecturer)->withHeader('X-Inertia', 'true')->post("/meetings/{$m->uuid}/join")->assertStatus(409)->assertHeader('X-Inertia-Location', route('meetings.room', $m));
        // a meeting that must use the meeting server says what is missing
        $forced = $this->schedule(['settings' => ['engine' => 'bbb']]);
        $this->flushHeaders()->actingAs($this->lecturer)->withHeader('X-Inertia', 'true')->post("/meetings/{$forced->uuid}/join")->assertStatus(503);
    }

    public function test_an_institutions_own_server_is_preferred(): void
    {
        $this->fakeBbb(['own.test/*' => Http::response('<response><returncode>SUCCESS</returncode><createTime>1</createTime></response>')]);
        $own = ConferenceServer::create(['tenant_id' => $this->tenant->id, 'name' => 'Campus', 'base_url' => 'https://own.test/bigbluebutton', 'secret' => 'campus-secret-0123456789', 'checksum_algo' => 'sha1']);
        $m = $this->schedule();
        $this->actingAs($this->lecturer)->withHeader('X-Inertia', 'true')->post("/meetings/{$m->uuid}/join")->assertStatus(409);
        $this->assertSame($own->id, $m->fresh()->conference_server_id);
    }

    // ---------- webhooks and attendance ----------

    private function event(Meeting $m, string $type, ?User $u, int $ts, string $internal = 'w_1'): array
    {
        return ['data' => ['type' => 'event', 'id' => $type, 'attributes' => array_filter([
            'meeting' => ['internal-meeting-id' => 'int-'.$m->id, 'external-meeting-id' => $m->bbb_meeting_id],
            'user' => $u ? ['internal-user-id' => $internal, 'external-user-id' => 'u'.$u->id, 'name' => $u->name, 'role' => 'VIEWER'] : null,
        ]), 'event' => ['ts' => $ts]]];
    }

    private function hook(array $events, string $algo = 'sha256', ?string $secret = null): \Illuminate\Testing\TestResponse
    {
        $url = route('conferencing.webhook', $this->server->id);
        $body = http_build_query(['domain' => 'bbb.test', 'event' => json_encode($events), 'timestamp' => 1], '', '&', PHP_QUERY_RFC1738);
        $sum = hash($algo, $url.$body.($secret ?? self::SECRET));

        return $this->call('POST', $url.'?checksum='.$sum, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body);
    }

    public function test_webhooks_need_the_servers_checksum(): void
    {
        $m = $this->schedule();
        $t = now()->getTimestampMs();
        $this->hook([$this->event($m, 'user-joined', $this->student, $t)], 'sha256', 'wrong-secret')->assertUnauthorized();
        $this->assertSame(0, \DB::table('meeting_events')->count());
        $this->hook([$this->event($m, 'user-joined', $this->student, $t)], 'sha1')->assertOk()->assertJson(['stored' => 1]);
        // the bearer-token form that newer bbb-webhooks uses
        $this->withHeader('Authorization', 'Bearer '.self::SECRET)->post(route('conferencing.webhook', $this->server->id), ['event' => json_encode([$this->event($m, 'user-left', $this->student, $t + 1000)])])->assertOk();
        $this->flushHeaders()->withHeader('Authorization', 'Bearer nope')->post(route('conferencing.webhook', $this->server->id), ['event' => '[]'])->assertUnauthorized();
    }

    public function test_attendance_comes_from_join_and_leave_events_with_reconnects_duplicates_and_late_delivery(): void
    {
        $m = $this->schedule();
        $m->update(['status' => 'live', 'started_at' => now()->subMinutes(60), 'starts_at' => now()->subMinutes(60)]);
        $t0 = now()->subMinutes(58)->getTimestampMs();
        $min = 60_000;
        $joined = $this->event($m, 'user-joined', $this->student, $t0, 'w_a');
        $left = $this->event($m, 'user-left', $this->student, $t0 + 20 * $min, 'w_a');
        $rejoin = $this->event($m, 'user-joined', $this->student, $t0 + 22 * $min, 'w_b');
        $end = $this->event($m, 'meeting-ended', null, $t0 + 50 * $min);

        // delivered out of order, with a duplicate
        $this->hook([$rejoin, $left])->assertJson(['stored' => 2]);
        $this->hook([$joined, $left])->assertJson(['stored' => 1]);
        $this->hook([$end])->assertJson(['stored' => 1]);

        $this->assertSame('ended', $m->fresh()->status);
        $rows = app(Attendance::class)->summary($m->fresh());
        $this->assertCount(1, $rows);
        $this->assertSame($this->student->id, $rows[0]['user_id']);
        $this->assertSame(2, $rows[0]['sessions']);
        $this->assertSame(1, $rows[0]['reconnects']);
        $this->assertSame(48 * 60, $rows[0]['seconds']); // 20 + 28 minutes
        $this->assertSame(80, $rows[0]['percent']);
        $this->assertSame('present', $rows[0]['status']);
        $this->assertTrue(MeetingAttendance::where('internal_user_id', 'w_b')->value('closed_by_meeting_end'));

        // the student sees only their own row; the lecturer sees everyone and can export
        $this->actingAs($this->student)->get("/meetings/{$m->uuid}")->assertInertia(fn (Assert $p) => $p->has('attendance', 1));
        $this->actingAs($this->student)->get("/meetings/{$m->uuid}/attendance.csv")->assertForbidden();
        $csv = $this->actingAs($this->lecturer)->get("/meetings/{$m->uuid}/attendance.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString($this->student->identifier, $csv);
    }

    public function test_events_naming_a_person_from_another_institution_are_not_trusted(): void
    {
        $m = $this->schedule();
        $rival = app(TenantProvisioner::class)->provision(['slug' => 'rival', 'name' => 'Rival'], 'enterprise');
        $theirs = app(TenantContext::class)->run($rival, fn () => User::factory()->create(['user_type' => 'student']));
        app(TenantContext::class)->set($this->tenant);
        $this->hook([$this->event($m, 'user-joined', $theirs, now()->getTimestampMs())])->assertOk();
        $this->assertNull(\DB::table('meeting_events')->value('user_id'));
    }

    public function test_an_institutions_own_server_cannot_report_on_other_meetings(): void
    {
        $m = $this->schedule();
        $elsewhere = app(TenantProvisioner::class)->provision(['slug' => 'elsewhere', 'name' => 'Elsewhere'], 'starter');
        app(TenantContext::class)->set($this->tenant);
        $own = ConferenceServer::create(['tenant_id' => $elsewhere->id, 'name' => 'Elsewhere', 'base_url' => 'https://x.test', 'secret' => 'other-secret-0123456789', 'checksum_algo' => 'sha256']);
        $url = route('conferencing.webhook', $own->id);
        $body = http_build_query(['event' => json_encode([$this->event($m, 'user-joined', $this->student, now()->getTimestampMs())])]);
        $this->call('POST', $url.'?checksum='.hash('sha256', $url.$body.'other-secret-0123456789'), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertJson(['stored' => 0]);
    }

    public function test_the_end_callback_needs_our_signature(): void
    {
        $m = $this->schedule();
        $this->get('/api/conferencing/ended/'.$m->uuid)->assertForbidden();
        $this->get(URL::signedRoute('conferencing.ended', ['meeting' => $m->uuid]))->assertOk();
        $this->assertSame('ended', $m->fresh()->status);
    }

    // ---------- recordings ----------

    public function test_recordings_are_synced_and_played_only_through_a_short_signed_link(): void
    {
        $this->fakeBbb(['bbb.test/bigbluebutton/api/getRecordings*' => Http::response('<response><returncode>SUCCESS</returncode><recordings><recording><recordID>rec-1</recordID><state>published</state><startTime>1762400000000</startTime><endTime>1762403600000</endTime><participants>14</participants><playback><format><type>presentation</type><url>https://bbb.test/playback/presentation/2.3/rec-1</url><length>60</length></format></playback></recording></recordings></response>')]);
        $m = $this->schedule();
        $m->update(['conference_server_id' => $this->server->id, 'status' => 'ended']);
        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/recordings/sync")->assertSessionHas('success');
        $rec = MeetingRecording::sole();
        $this->assertSame('published', $rec->state);
        $this->assertSame(3600, $rec->duration_seconds);

        // the page lists it without its address
        $page = $this->actingAs($this->student)->get("/meetings/{$m->uuid}")->assertInertia(fn (Assert $p) => $p->has('recordings', 1))->getContent();
        $this->assertStringNotContainsString('playback/presentation', $page);
        // an enrolled student is sent through a signed link that only works for them
        $to = $this->actingAs($this->student)->get("/meetings/{$m->uuid}/recordings/{$rec->id}")->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('signature=', $to);
        $this->actingAs($this->student)->get($to)->assertRedirect('https://bbb.test/playback/presentation/2.3/rec-1');
        $this->actingAs($this->lecturer)->get($to)->assertForbidden();
        // hosts-only recordings are closed to students; outsiders never get in
        $rec->update(['visibility' => 'hosts']);
        $this->actingAs($this->student)->get("/meetings/{$m->uuid}/recordings/{$rec->id}")->assertForbidden();
        $this->actingAs($this->student())->get("/meetings/{$m->uuid}/recordings/{$rec->id}")->assertForbidden();
    }

    // ---------- servers, module, branding ----------

    public function test_servers_are_managed_by_the_right_people_and_secrets_stay_hidden(): void
    {
        $this->fakeBbb();
        $platform = User::factory()->create();
        $platform->forceFill(['tenant_id' => null, 'is_platform_admin' => true, 'user_type' => 'platform', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($platform)->post('/platform/conferencing/servers', ['name' => 'Shared 2', 'base_url' => 'https://bbb.test/bigbluebutton/', 'secret' => 'another-secret-0123456789', 'checksum_algo' => 'sha256', 'enabled' => true])->assertSessionHas('success');
        $id = ConferenceServer::where('name', 'Shared 2')->value('id');
        $page = $this->actingAs($platform)->get('/platform/conferencing')->assertOk()->getContent();
        $this->assertStringNotContainsString('another-secret-0123456789', $page);
        $this->actingAs($platform)->post("/platform/conferencing/servers/$id/check", ['register_webhook' => true])->assertSessionHas('success');
        $this->assertSame(12, ConferenceServer::find($id)->live_participants);
        $this->assertSame('3.0', ConferenceServer::find($id)->version);
        // a lecturer cannot reach server settings; the institution admin manages only its own servers
        $this->actingAs($this->lecturer)->get('/admin/conferencing')->assertForbidden();
        $admin = User::factory()->create(['user_type' => 'staff']);
        $admin->assignRole('institution_admin');
        $this->actingAs($admin)->get('/admin/conferencing')->assertOk()->assertInertia(fn (Assert $p) => $p->has('servers', 0)->where('shared', 2));
        $this->actingAs($admin)->put("/admin/conferencing/servers/$id", ['name' => 'hijack', 'base_url' => 'https://evil.test', 'checksum_algo' => 'sha256'])->assertNotFound();
    }

    public function test_conferencing_follows_the_subscription_module(): void
    {
        $this->tenant->plan->update(['modules' => ['question_bank', 'lms']]);
        $this->tenant->moduleOverrides()->delete();
        \Illuminate\Support\Facades\Cache::flush();
        $this->actingAs($this->lecturer->fresh())->get('/meetings')->assertForbidden();
    }

    public function test_the_platform_brands_itself_and_institutions_keep_their_own(): void
    {
        $platform = User::factory()->create();
        $platform->forceFill(['tenant_id' => null, 'is_platform_admin' => true, 'user_type' => 'platform', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($platform)->post('/platform/branding', ['brand_name' => 'CampusOne Exams & Learning', 'brand_short_name' => 'CampusOne', 'brand_color' => '#224466'])->assertSessionHas('success');
        $this->assertSame('CampusOne', PlatformSettings::brand()['short_name']);
        auth()->logout();
        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('platform.short_name', 'CampusOne')->where('tenant', null))->assertSee('CampusOne</title>', false)->assertSee('--brand: #224466', false);
        // inside an institution its own name and colour lead
        $this->tenant->update(['brand_color' => '#aa3300']);
        $this->actingAs($this->student)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('tenant.brand', '#aa3300')->where('platform.short_name', 'CampusOne'));
        $this->actingAs($this->lecturer)->post('/platform/branding', ['brand_name' => 'x', 'brand_color' => '#000000'])->assertForbidden();
    }

    // ---------- in-room options, slides, vivas, transcripts, reports, health ----------

    public function test_room_layout_switched_off_features_listen_only_and_slides_reach_bigbluebutton(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->fakeBbb();
        $m = $this->schedule(['settings' => ['layout' => 'PRESENTATION_FOCUS', 'disabled_features' => ['polls', 'chat'], 'listen_only' => true, 'skip_audio_check' => true]]);
        $this->actingAs($this->lecturer)->post('/meetings', ['title' => 'x', 'type' => 'class', 'starts_at' => now()->addHour()->format('Y-m-d H:i'), 'duration_minutes' => 60, 'timezone' => 'UTC', 'access' => 'invited',
            'settings' => ['disabled_features' => ['notAFeature']]])->assertSessionHasErrors('settings.disabled_features.0');

        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/slides", ['file' => \Illuminate\Http\UploadedFile::fake()->create('week1.pdf', 200, 'application/pdf')])->assertSessionHas('success');
        $this->actingAs($this->student)->post("/meetings/{$m->uuid}/slides", ['file' => \Illuminate\Http\UploadedFile::fake()->create('mine.pdf', 10, 'application/pdf')])->assertForbidden();
        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/slides", ['file' => \Illuminate\Http\UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
        $this->assertCount(1, $m->fresh()->setting('slides'));
        // a slide path cannot be smuggled in through the meeting form
        $this->actingAs($this->lecturer)->put("/meetings/{$m->uuid}", ['title' => $m->title, 'type' => 'class', 'course_offering_id' => $this->offering->id, 'starts_at' => now()->addMinutes(5)->format('Y-m-d H:i'),
            'duration_minutes' => 60, 'timezone' => 'UTC', 'access' => 'course', 'settings' => ['slides' => [['path' => '../../.env', 'name' => 'x']]]]);
        $this->assertStringStartsWith('meeting-slides/', $m->fresh()->setting('slides')[0]['path']);

        $this->actingAs($this->lecturer)->withHeader('X-Inertia', 'true')->post("/meetings/{$m->uuid}/join")->assertStatus(409);
        $slideUrl = null;
        Http::assertSent(function ($req) use (&$slideUrl) {
            if (! str_contains($req->url(), '/api/create')) {
                return false;
            }
            $this->assertStringContainsString('meetingLayout=PRESENTATION_FOCUS', $req->url());
            $this->assertStringContainsString('disabledFeatures=polls%2Cchat', $req->url());
            $this->assertSame('POST', $req->method());
            preg_match('/document url="([^"]+)"/', $req->body(), $mm);
            $slideUrl = html_entity_decode($mm[1] ?? '', ENT_XML1 | ENT_QUOTES);

            return str_contains($req->body(), 'filename="week1.pdf"');
        });
        // BigBlueButton fetches the slides with the signed link, without a session; tampering fails
        auth()->logout();
        $this->flushHeaders()->get($slideUrl)->assertOk();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $slideUrl))->assertForbidden();
        $this->get(str_replace('/0?', '/1?', $slideUrl))->assertForbidden();

        $url = $this->flushHeaders()->actingAs($this->student)->withHeader('X-Inertia', 'true')->post("/meetings/{$m->uuid}/join")->assertStatus(409)->headers->get('X-Inertia-Location');
        $this->assertStringContainsString('userdata-bbb_listen_only_mode=true', $url);
        $this->assertStringContainsString('userdata-bbb_skip_check_audio=true', $url);

        $this->flushHeaders()->actingAs($this->lecturer)->delete("/meetings/{$m->uuid}/slides/0")->assertRedirect();
        $this->assertCount(0, $m->fresh()->setting('slides'));
    }

    public function test_a_viva_held_in_a_room_lets_its_examiners_host_and_score_and_its_candidates_join(): void
    {
        $this->fakeBbb();
        $examiner = User::factory()->create(['user_type' => 'staff', 'name' => 'Prof Viva']);
        $examiner->assignRole('lecturer');
        $officer = $this->officer;
        $viva = \App\Models\Exam::create(['title' => 'MSc viva', 'type' => 'viva', 'status' => 'scheduled', 'duration_minutes' => 60, 'max_attempts' => 1, 'total_mark' => 100, 'created_by' => $officer->id]);
        $viva->examiners()->attach($examiner->id, ['role' => 'internal']);
        $cand = $this->student();
        $this->candidate($this->sitting($viva), $cand);
        $cbt = $this->cbtExam();

        $this->actingAs($officer)->post('/meetings', ['title' => 'Viva room', 'type' => 'viva', 'starts_at' => now()->addMinutes(5)->format('Y-m-d H:i'), 'duration_minutes' => 60, 'timezone' => 'UTC',
            'access' => 'invited', 'settings' => ['exam_id' => $cbt->id]])->assertSessionHasErrors('settings.exam_id');
        $this->actingAs($officer)->post('/meetings', ['title' => 'Viva room', 'type' => 'viva', 'starts_at' => now()->addMinutes(5)->format('Y-m-d H:i'), 'duration_minutes' => 60, 'timezone' => 'UTC',
            'access' => 'invited', 'settings' => ['exam_id' => $viva->id]])->assertSessionHasNoErrors();
        $m = Meeting::latest('id')->firstOrFail();

        $this->actingAs($examiner)->get("/meetings/{$m->uuid}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.moderate', true)
            ->where('exam.id', $viva->id)->has('exam.candidates', 1)->where('exam.candidates.0.user_id', $cand->id)->where('exam.candidates.0.scored', false));
        $this->actingAs($cand)->get("/meetings/{$m->uuid}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.moderate', false)->where('exam.candidates', []));
        $this->actingAs($this->student())->get("/meetings/{$m->uuid}")->assertForbidden();
    }

    public function test_recordings_get_transcripts_and_translations_from_the_configured_services(): void
    {
        config(['conferencing.transcription.url' => 'https://stt.test', 'conferencing.transcription.key' => 'k1', 'conferencing.translation.url' => 'https://tr.test']);
        $this->fakeBbb([
            'bbb.test/presentation/rec-9/video/webcams.mp4' => Http::response(str_repeat('a', 5000)),
            'stt.test/v1/audio/transcriptions' => Http::response(['language' => 'english', 'text' => 'Good morning. Today: moles.', 'segments' => [['start' => 0, 'end' => 2.5, 'text' => ' Good morning.'], ['start' => 2.5, 'end' => 61.2, 'text' => 'Today: moles.']]]),
            'tr.test/translate' => Http::response(['translatedText' => ['Bonjour.', 'Aujourd’hui : les moles.']]),
        ]);
        $m = $this->schedule();
        $m->update(['conference_server_id' => $this->server->id, 'status' => 'ended']);
        $rec = MeetingRecording::create(['meeting_id' => $m->id, 'record_id' => 'rec-9', 'state' => 'published', 'format' => 'presentation', 'playback_url' => 'https://bbb.test/playback/presentation/2.3/rec-9', 'visibility' => 'attendees']);

        $this->actingAs($this->student)->post("/meetings/{$m->uuid}/recordings/{$rec->id}/transcribe")->assertForbidden();
        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/recordings/{$rec->id}/transcribe")->assertSessionHas('success');
        $t = \App\Models\MeetingTranscript::sole();
        $this->assertSame('complete', $t->status);
        $this->assertSame('english', $t->language);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'stt.test') && $r->hasHeader('Authorization', 'Bearer k1'));

        $vtt = $this->actingAs($this->student)->get("/meetings/{$m->uuid}/transcripts/{$t->id}.vtt")->assertOk()->getContent();
        $this->assertStringStartsWith('WEBVTT', $vtt);
        $this->assertStringContainsString("00:00:02.500 --> 00:01:01.200\nToday: moles.", $vtt);
        $this->assertStringContainsString('00:00:00,000 --> 00:00:02,500', $this->actingAs($this->lecturer)->get("/meetings/{$m->uuid}/transcripts/{$t->id}.srt")->getContent());

        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/transcripts/{$t->id}/translate", ['language' => 'xx'])->assertSessionHasErrors('language');
        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/transcripts/{$t->id}/translate", ['language' => 'fr'])->assertSessionHas('success');
        $fr = \App\Models\MeetingTranscript::where('kind', 'translation')->sole();
        $this->assertSame('complete', $fr->status);
        $this->assertSame('Bonjour.', $fr->segments[0]['text']);
        $this->assertSame(2.5, (float) $fr->segments[1]['start']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'tr.test') && $r['source'] === 'en' && $r['target'] === 'fr');

        // hosts-only recordings keep their transcripts from students; outsiders never see them
        $rec->update(['visibility' => 'hosts']);
        $this->actingAs($this->student)->get("/meetings/{$m->uuid}/transcripts/{$t->id}.txt")->assertForbidden();
        $this->actingAs($this->student())->get("/meetings/{$m->uuid}/transcripts/{$t->id}.txt")->assertForbidden();

        // a failing service is reported on the transcript, not as an error page
        Http::fake(['bbb.test/*' => Http::response('', 404), '*' => Http::response('', 500)]);
        $t->update(['status' => 'failed']);
        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/recordings/{$rec->id}/transcribe")->assertSessionHas('success');
        $this->assertSame('failed', \App\Models\MeetingTranscript::where('kind', 'transcript')->latest('id')->first()->status);
    }

    public function test_transcription_is_offered_only_when_a_service_is_set_up(): void
    {
        config(['conferencing.transcription.url' => '']);
        $m = $this->schedule();
        $rec = MeetingRecording::create(['meeting_id' => $m->id, 'record_id' => 'rec-2', 'state' => 'published', 'playback_url' => 'https://bbb.test/p/rec-2', 'visibility' => 'attendees']);
        $this->actingAs($this->lecturer)->get("/meetings/{$m->uuid}")->assertInertia(fn (Assert $p) => $p->where('transcription.enabled', false));
        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/recordings/{$rec->id}/transcribe")->assertStatus(422);
    }

    public function test_course_attendance_reports_show_each_class_and_student_and_download(): void
    {
        $other = $this->student();
        Enrolment::create(['course_offering_id' => $this->offering->id, 'user_id' => $other->id, 'status' => 'active']);
        $m = $this->schedule();
        $m->update(['status' => 'ended', 'started_at' => $m->starts_at, 'ended_at' => $m->starts_at->copy()->addHour()]);
        MeetingAttendance::create(['tenant_id' => $m->tenant_id, 'meeting_id' => $m->id, 'user_id' => $this->student->id, 'internal_user_id' => 'w_1', 'name' => 'S',
            'joined_at' => $m->starts_at, 'left_at' => $m->starts_at->copy()->addMinutes(55), 'seconds' => 3300]);

        $this->actingAs($this->student)->get('/meeting-reports')->assertForbidden();
        $this->actingAs($this->lecturer)->get('/meeting-reports?course='.$this->offering->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('meetings/reports')
            ->where('report.summary.classes', 1)->where('report.summary.students', 2)->where('report.summary.average_turnout', 50)->where('report.summary.never', 1)
            ->where('report.meetings.0.attended', 1));
        $x = $this->actingAs($this->lecturer)->get('/meeting-reports/export?course='.$this->offering->id.'&format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('Content-Type'));
        $this->assertStringContainsString('pdf', $this->actingAs($this->lecturer)->get('/meeting-reports/export?course='.$this->offering->id.'&format=pdf')->headers->get('Content-Type'));
        // a lecturer who does not teach the course cannot report on it
        $stranger = User::factory()->create(['user_type' => 'staff']);
        $stranger->assignRole('lecturer');
        $this->assertContains($this->actingAs($stranger)->get('/meeting-reports/export?course='.$this->offering->id)->status(), [403, 404]);
    }

    public function test_the_health_check_marks_servers_closes_forgotten_rooms_and_refreshes_recordings(): void
    {
        $this->fakeBbb([
            'bbb.test/bigbluebutton/api/isMeetingRunning*' => Http::response('<response><returncode>SUCCESS</returncode><running>false</running></response>'),
            'bbb.test/bigbluebutton/api/getRecordings*' => Http::response('<response><returncode>SUCCESS</returncode><recordings><recording><recordID>rec-h</recordID><state>published</state><startTime>1762400000000</startTime><endTime>1762403600000</endTime><playback><format><type>presentation</type><url>https://bbb.test/playback/presentation/2.3/rec-h</url><length>60</length></format></playback></recording></recordings></response>'),
        ]);
        $broken = ConferenceServer::create(['tenant_id' => null, 'name' => 'Down', 'base_url' => 'https://down.test/bigbluebutton/', 'secret' => 's', 'status' => 'online']);
        Http::fake(['down.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused')]);
        $m = $this->schedule();
        $m->update(['conference_server_id' => $this->server->id, 'status' => 'live', 'starts_at' => now()->subHours(3), 'started_at' => now()->subHours(3)]);

        $this->artisan('conferencing:check')->assertSuccessful();
        $this->assertSame('online', $this->server->fresh()->status);
        $this->assertSame(12, $this->server->fresh()->live_participants);
        $this->assertSame('offline', $broken->fresh()->status);
        $this->assertSame('ended', $m->fresh()->status);
        $this->assertSame(1, MeetingRecording::where('record_id', 'rec-h')->count());
    }
}
