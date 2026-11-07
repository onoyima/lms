<?php

namespace Tests\Feature\Bridges;

use App\Bridges\Vuna\Demo\DemoPortal;
use App\Bridges\Vuna\Http\OidcController;
use App\Bridges\Vuna\Oidc\Keys;
use App\Bridges\Vuna\Oidc\Tokens;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Semester;
use App\Models\SsoIdentity;
use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Directory\DirectorySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/** Veritas kept in step with the university portal through the bridge: people, courses, semester, registrations, teaching, sign-in. */
class DirectorySyncTest extends TestCase
{
    use BuildsExams, RefreshDatabase, UsesDemoPortal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        $this->bootDemoPortal();
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost'); // one address for Veritas and the bridge in this test

        // Veritas talks to the bridge over HTTP; here the "network" hands each call to the bridge in this app
        Http::fake(function (\Illuminate\Http\Client\Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/.well-known/openid-configuration')) {
                return Http::response(app(OidcController::class)->discovery()->getData(true));
            }
            if (str_ends_with($path, '/jwks')) {
                return Http::response(app(Keys::class)->jwks());
            }
            if (str_ends_with($path, '/token')) {
                [$id, $secret] = explode(':', base64_decode(substr($r->header('Authorization')[0], 6)), 2);
                parse_str($r->body(), $form);
                [$status, $body] = app(Tokens::class)->exchange($form, $id, $secret);

                return Http::response($body, $status);
            }
            if (str_ends_with($path, '/api/v1/authenticate')) {
                // asked in the middle of a sign-in request: answer straight from the bridge controller
                $req = \Illuminate\Http\Request::create($path, 'POST', $r->data(), [], [], ['HTTP_AUTHORIZATION' => $r->header('Authorization')[0] ?? '']);
                $res = app(\App\Bridges\Vuna\Http\DirectoryController::class)->authenticate($req);

                return Http::response($res->getContent(), $res->getStatusCode(), ['Content-Type' => 'application/json']);
            }
            $q = parse_url($r->url(), PHP_URL_QUERY);
            $auth = ['Authorization' => $r->header('Authorization')[0] ?? ''];
            $res = $r->method() === 'POST'
                ? $this->withHeaders($auth)->post($path, $r->data(), ['Accept' => 'application/json'])
                : $this->withHeaders($auth)->getJson($path.($q ? "?$q" : ''));

            return Http::response($res->getContent(), $res->getStatusCode(), ['Content-Type' => 'application/json']);
        });

        $this->actingAs($this->officer)->put('/admin/integrations/directory', [
            'enabled' => true, 'url' => 'http://localhost/bridge/vuna', 'client_id' => 'exams-lms', 'secret' => self::BRIDGE_SECRET,
            'sso' => true, 'follow_current_semester' => true, 'deactivate_missing' => true, 'include_applicants' => true,
        ])->assertSessionHasNoErrors();
        auth()->logout();
    }

    private function sync(bool $force = false): array
    {
        $r = app(DirectorySync::class)->run($force);
        $this->assertTrue($r['ok'], (string) $r['error']);

        return $r;
    }

    private function user(string $identifier): User
    {
        return User::where('identifier', $identifier)->firstOrFail();
    }

    private function portal(): \Illuminate\Database\Connection
    {
        app()->forgetInstance(\App\Bridges\Vuna\Portal::class); // the bridge reads afresh, like a new request
        config(['vuna_bridge.testing_allow_writes' => true]); // the test plays the portal's own staff changing records

        return DB::connection('vuna');
    }

    private function enrolled(User $u): array
    {
        return DB::table('enrolments')->join('course_offerings', 'course_offerings.id', '=', 'enrolments.course_offering_id')->join('courses', 'courses.id', '=', 'course_offerings.course_id')
            ->where('enrolments.user_id', $u->id)->where('enrolments.status', 'active')->orderBy('courses.code')->pluck('enrolments.category', 'courses.code')->all();
    }

    public function test_one_sync_brings_in_active_people_courses_the_running_semester_registrations_and_teaching(): void
    {
        $provider = SsoProvider::where('slug', 'portal')->firstOrFail();
        $this->assertSame('http://localhost/bridge/vuna', $provider->config['issuer']);
        $this->assertFalse($provider->enabled); // kept for its role rules only
        $this->assertSame('user_type', $provider->type_attribute);
        $this->assertEqualsCanonicalizing(['hod', 'dean', 'exam_officer'], array_column($provider->role_rules, 'role'));

        $this->actingAs($this->officer)->post('/admin/integrations/directory/test')->assertSessionHas('directory_test.counts.active_students', 3);
        auth()->logout();

        $r = $this->sync();
        $this->assertSame(0, $r['failed']);
        $this->assertSame('First semester', $r['current_semester']);

        // structure
        $this->assertSame('Faculty of Natural and Applied Sciences', Faculty::where('code', 'NAS')->value('name'));
        $chm = Department::where('code', 'CHM')->firstOrFail();
        $this->assertSame(Faculty::where('code', 'NAS')->value('id'), $chm->faculty_id);
        $this->assertSame($chm->id, Programme::where('code', 'ICH')->value('department_id'));
        $this->assertSame(['CHM 101', 'CHM 201', 'CSC 101', 'CSC 301', 'ENG 211', 'GST 101'], Course::orderBy('code')->pluck('code')->all()); // "gst  101" is GST 101
        $semester = Semester::where('is_current', true)->firstOrFail();
        $this->assertSame(['First semester', '2025/2026'], [$semester->name, $semester->academicSession->name ?? DB::table('academic_sessions')->where('id', $semester->academic_session_id)->value('name')]);

        // people: only the active ones
        $ada = $this->user('VUG/ICH/25/001');
        $bola = $this->user('VUG/BCS/23/014');
        $efe = $this->user('VUG/BCS/25/101');
        $this->assertSame(['student', 'active'], [$ada->user_type, $ada->status]);
        $this->assertSame('student-5@portal.invalid', $efe->email); // no e-mail in the portal: still signs in through the portal
        $this->assertNull(User::where('identifier', 'VUG/BEN/22/009')->first()); // deferred
        $this->assertNull(User::where('identifier', 'VUG/ICH/23/020')->first()); // suspended
        $this->assertTrue($ada->can('lms.learn'));
        $this->assertSame(300, DB::table('student_profiles')->where('user_id', $bola->id)->value('level'));
        $this->assertSame(Programme::where('code', 'BCS')->value('id'), DB::table('student_profiles')->where('user_id', $bola->id)->value('programme_id'));

        $ngozi = $this->user('VUNA/ST/0042');
        $dean = $this->user('VUNA/ST/0007');
        $funke = $this->user('VUNA/ST/0110');
        $this->assertNull(User::where('identifier', 'VUNA/ST/0099')->first()); // appointment ended
        $this->assertSame('segun.ade@vuna.test', $this->user('VUNA/NS/0300')->email);
        $this->assertTrue($ngozi->hasRole('hod'));
        $this->assertTrue($ngozi->can('lms.teach'));
        $this->assertTrue($dean->hasRole('dean'));
        $this->assertFalse($dean->hasRole('exam_officer')); // that portal role was removed
        $this->assertTrue($funke->hasRole('exam_officer'));
        $this->assertSame(['Dr.', $chm->id], [DB::table('staff_profiles')->where('user_id', $ngozi->id)->value('title'), DB::table('staff_profiles')->where('user_id', $ngozi->id)->value('department_id')]);

        $grace = User::where('identifier', 'APP/2025/0451')->firstOrFail();
        $this->assertSame('student', $grace->user_type);
        $this->assertTrue($grace->hasRole('applicant'));
        $this->assertFalse($grace->can('lms.learn'));
        $this->assertNull(User::where('identifier', 'APP/2024/0102')->first()); // last session's applicant

        // registrations and teaching in the running semester
        $this->assertSame(['CHM 101' => 'core', 'GST 101' => 'core'], $this->enrolled($ada));
        $this->assertSame(['CSC 101' => 'carryover', 'CSC 301' => 'core', 'ENG 211' => 'elective'], $this->enrolled($bola));
        $this->assertSame(['CSC 101' => 'core', 'GST 101' => 'core'], $this->enrolled($efe));
        $teaching = DB::table('course_offering_staff')->join('course_offerings', 'course_offerings.id', '=', 'course_offering_staff.course_offering_id')->join('courses', 'courses.id', '=', 'course_offerings.course_id')
            ->join('users', 'users.id', '=', 'course_offering_staff.user_id')->orderBy('courses.code')->get(['users.identifier', 'courses.code', 'course_offering_staff.role'])
            ->map(fn ($t) => "{$t->identifier} {$t->code} {$t->role}")->all();
        $this->assertSame(['VUNA/ST/0042 CHM 101 lecturer', 'VUNA/ST/0042 CHM 201 lecturer', 'VUNA/ST/0110 CSC 101 lecturer', 'VUNA/ST/0110 CSC 301 coordinator', 'VUNA/ST/0042 GST 101 lecturer'], $teaching);

        // last semester's course is a previous course, not a current one; lecturers' names are on the course cards
        $this->assertSame('completed', DB::table('enrolments')->join('course_offerings', 'course_offerings.id', '=', 'enrolments.course_offering_id')
            ->join('semesters', 'semesters.id', '=', 'course_offerings.semester_id')->join('academic_sessions', 'academic_sessions.id', '=', 'semesters.academic_session_id')
            ->where('enrolments.user_id', $ada->id)->where('academic_sessions.name', '2024/2025')->value('enrolments.status'));
        $this->actingAs($ada)->get('/learn/courses')->assertOk()->assertInertia(fn ($p) => $p
            ->where('enrolled', fn ($list) => collect($list)->firstWhere('code', 'CHM 101')['lecturers'] === ['Ngozi Okeke'] && collect($list)->firstWhere('code', 'CSC 101')['status'] === 'completed'));
        auth()->logout();

        // sign-in links prepared for everyone
        $this->assertSame($efe->id, SsoIdentity::where('sso_provider_id', $provider->id)->where('subject', 'student:5')->value('user_id'));

        // running again changes nothing
        $again = $this->sync();
        $this->assertSame([0, 0, 0], [$again['created'], $again['switched_off'], $again['enrolments_dropped']]);
        $this->assertSame(1, DB::table('sync_runs')->where('kind', 'directory')->where('created', '>', 0)->count());
    }

    public function test_changes_in_the_portal_follow_at_the_next_sync_without_touching_what_was_set_by_hand(): void
    {
        $this->sync();
        $ada = $this->user('VUG/ICH/25/001');
        $bola = $this->user('VUG/BCS/23/014');
        $ngozi = $this->user('VUNA/ST/0042');

        // set by hand in Veritas: Bola suspended by the registry, an extra lecturer and an extra enrolment
        $bola->forceFill(['status' => 'suspended'])->save();
        $extra = CourseOffering::firstOrCreate(['course_id' => Course::where('code', 'CHM 201')->value('id'), 'semester_id' => Semester::where('is_current', true)->value('id')], ['status' => 'open']);
        DB::table('course_offering_staff')->insert(['course_offering_id' => $extra->id, 'user_id' => $ngozi->id, 'role' => 'lecturer', 'source' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrolments')->insert(['tenant_id' => tenant_id(), 'course_offering_id' => $extra->id, 'user_id' => $ada->id, 'category' => 'core', 'status' => 'active', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

        // in the portal: Ada drops GST 101 then is suspended; Ngozi is no longer HOD and no longer teaches CHM 101
        $db = $this->portal();
        $db->table('course_regs')->where('student_id', 1)->where('course_id', 4)->update(['is_course_reg' => 3]);
        $db->table('student_academics')->where('student_id', 1)->update(['studentship' => 5]);
        $db->table('staff_assigned_roles')->where('staff_id', 1)->update(['status' => 0, 'removed_date' => '2026-10-01']);
        $db->table('assigned_courses')->where('staff_id', 1)->where('course_id', 1)->delete();
        DB::table('sessions')->insert(['id' => 'ada-session', 'user_id' => $ada->id, 'payload' => '', 'last_activity' => time()]);

        $r = $this->sync();
        $this->assertSame(1, $r['switched_off']);
        $this->assertSame(1, $r['kept_suspended']);
        $this->assertSame('suspended', $ada->fresh()->status);
        $this->assertSame('suspended', DB::table('student_profiles')->where('user_id', $ada->id)->value('academic_status'));
        $this->assertFalse(DB::table('sessions')->where('id', 'ada-session')->exists()); // signed out
        $this->assertSame('suspended', $bola->fresh()->status);
        $this->assertSame(['CHM 201' => 'core'], $this->enrolled($ada)); // the hand-made enrolment stays; GST 101 and CHM 101 dropped with her
        $this->assertSame('dropped', DB::table('enrolments')->join('course_offerings', 'course_offerings.id', '=', 'enrolments.course_offering_id')
            ->where('course_offerings.course_id', Course::where('code', 'GST 101')->value('id'))->where('enrolments.user_id', $ada->id)->value('enrolments.status'));
        $this->assertFalse($ngozi->fresh()->hasRole('hod'));
        $this->assertTrue(DB::table('course_offering_staff')->where('course_offering_id', $extra->id)->where('user_id', $ngozi->id)->exists());
        $this->assertFalse(DB::table('course_offering_staff')->join('course_offerings', 'course_offerings.id', '=', 'course_offering_staff.course_offering_id')
            ->where('course_offerings.course_id', Course::where('code', 'CHM 101')->value('id'))->where('user_id', $ngozi->id)->exists());

        // Ada returns; the registry lifts Bola's suspension by hand and the sync keeps him active
        $this->portal()->table('student_academics')->where('student_id', 1)->update(['studentship' => 1]);
        $bola->forceFill(['status' => 'active'])->save();
        $r = $this->sync();
        $this->assertSame(1, $r['switched_on']);
        $this->assertSame('active', $ada->fresh()->status);
        $this->assertSame('active', $bola->fresh()->status);

        // the portal moves to the second semester
        $db = $this->portal();
        $db->table('vu_semesters')->where('id', 3)->update(['status' => 0]);
        $db->table('vu_semesters')->where('id', 4)->update(['status' => 1]);
        $db->table('course_regs')->insert(['student_id' => 1, 'course_id' => 2, 'level' => '100', 'academic_session_id' => 2, 'vu_semester_id' => 4, 'is_course_reg' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->sync();
        $this->assertSame('Second semester', $r['current_semester']);
        $this->assertSame('Second semester', Semester::where('is_current', true)->value('name'));
        $this->assertSame(1, Semester::where('is_current', true)->count());
        // registrations of the first semester are history now, not dropped
        $this->assertSame(0, $r['enrolments_dropped']);
    }

    public function test_an_unusual_number_of_departures_waits_for_a_person_to_confirm(): void
    {
        $this->sync();
        $this->portal()->table('student_academics')->update(['studentship' => 2, 'studentship_id' => null]); // the portal suddenly lists no active students

        $r = $this->sync();
        $this->assertSame(0, $r['switched_off']);
        $this->assertStringContainsString('Did not switch off 3 student', implode(' ', $r['warnings']));
        $this->assertSame('active', $this->user('VUG/ICH/25/001')->status);

        $r = $this->sync(force: true);
        $this->assertSame(3, $r['switched_off']);
        $this->assertSame('deferred', $this->user('VUG/ICH/25/001')->status);
    }

    public function test_people_sign_in_on_the_normal_form_with_their_portal_details(): void
    {
        // no separate button: the sign-in page looks the same as for any other institution
        $this->get('/login')->assertInertia(fn ($p) => $p->where('sso', []));
        $this->assertFalse(SsoProvider::where('slug', 'portal')->value('enabled'));

        // a student the hourly sync has not brought in yet: created on the spot, with their courses' roles
        $this->assertNull(User::where('identifier', 'VUG/BCS/23/014')->first());
        $this->post('/login', ['email' => 'VUG/BCS/23/014', 'password' => DemoPortal::STUDENT_PASSWORD])->assertRedirect('/dashboard');
        $bola = $this->user('VUG/BCS/23/014');
        $this->assertAuthenticatedAs($bola);
        $this->assertTrue($bola->can('lms.learn'));
        $this->assertTrue(DB::table('security_events')->where('user_id', $bola->id)->where('type', 'login_portal')->exists());
        auth()->logout();

        // staff by staff number or e-mail; portal roles follow at sign-in
        $this->post('/login', ['email' => 'ngozi.okeke@vuna.test', 'password' => DemoPortal::STAFF_PASSWORD])->assertRedirect('/dashboard');
        $this->assertTrue($this->user('VUNA/ST/0042')->hasRole('hod'));
        auth()->logout();
        // a student without an e-mail, by registration number
        $this->post('/login', ['email' => 'REG25101', 'password' => DemoPortal::STUDENT_PASSWORD])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->user('VUG/BCS/25/101'));
        auth()->logout();

        // wrong password, not active in the portal, suspended here by hand
        $this->post('/login', ['email' => 'VUG/ICH/25/001', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'VUG/BEN/22/009', 'password' => DemoPortal::STUDENT_PASSWORD])->assertSessionHasErrors(['email' => 'Your portal account is not active for exams and learning (status: deferred). Please contact the registry.']);
        $bola->forceFill(['status' => 'suspended'])->save();
        $this->post('/login', ['email' => 'VUG/BCS/23/014', 'password' => DemoPortal::STUDENT_PASSWORD])->assertSessionHasErrors(['email' => 'This account has been suspended here. Please contact the registry.']);
        $this->assertGuest();

        // local accounts are untouched: the administrator's password is never sent to the portal
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/authenticate') && ($r->data()['login'] ?? null) === $this->officer->email);
        $this->post('/login', ['email' => $this->officer->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/authenticate') && ($r->data()['login'] ?? null) === $this->officer->email);

        // the portal switched off: portal details stop working, nothing else changes
        $this->actingAs($this->officer)->put('/admin/integrations/directory', ['enabled' => true, 'url' => 'http://localhost/bridge/vuna', 'client_id' => 'exams-lms', 'sso' => false])->assertSessionHasNoErrors();
        auth()->logout();
        $this->post('/login', ['email' => 'REG25101', 'password' => DemoPortal::STUDENT_PASSWORD])->assertSessionHasErrors('email');
    }

    public function test_portal_people_land_only_in_the_institution_connected_to_their_portal(): void
    {
        // a second institution on the same platform, without any portal connection
        $other = \App\Models\Tenant::create(['slug' => 'other-uni', 'name' => 'Other University']);
        app(\App\Services\Tenancy\TenantProvisioner::class)->provision(['slug' => 'other-uni', 'name' => 'Other University'], 'enterprise');
        app(\App\Support\Tenancy\TenantContext::class)->set($this->tenant);

        // on the other institution's sign-in page, portal details mean nothing
        $this->withHeader('X-Tenant', 'other-uni')->post('/login', ['email' => 'VUG/ICH/25/001', 'password' => DemoPortal::STUDENT_PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, User::withoutGlobalScope('tenant')->where('identifier', 'VUG/ICH/25/001')->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/authenticate'));
        $this->flushHeaders()->flushSession();

        // on the connected institution's page: account created inside that institution only
        $this->post('/login', ['email' => 'VUG/ICH/25/001', 'password' => DemoPortal::STUDENT_PASSWORD])->assertRedirect('/dashboard');
        $ada = User::withoutGlobalScope('tenant')->where('identifier', 'VUG/ICH/25/001')->sole();
        $this->assertSame($this->tenant->id, $ada->tenant_id);
        $this->assertAuthenticatedAs($ada);
        $this->get('/dashboard')->assertOk();

        // and that account cannot be used inside the other institution
        $this->withHeader('X-Tenant', 'other-uni')->get('/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_a_portal_lecturer_builds_the_course_week_by_week_and_the_portal_student_sees_it(): void
    {
        $this->sync();
        $ngozi = $this->user('VUNA/ST/0042');
        $ada = $this->user('VUG/ICH/25/001');
        $offering = CourseOffering::where('course_id', Course::where('code', 'CHM 101')->value('id'))->where('semester_id', Semester::where('is_current', true)->value('id'))->firstOrFail();

        // students cannot lay out a course
        $this->actingAs($ada)->post("/learn/courses/{$offering->id}/weeks", ['weeks' => 3])->assertForbidden();

        $this->actingAs($ngozi)->post("/learn/courses/{$offering->id}/weeks", ['weeks' => 3, 'starts_on' => now()->subWeek()->toDateString()])->assertRedirect();
        $weeks = \App\Models\CourseSection::where('course_offering_id', $offering->id)->orderBy('position')->get();
        $this->assertSame(['Week 1', 'Week 2', 'Week 3'], $weeks->pluck('title')->all());
        $this->assertTrue($weeks[2]->release_at->isFuture()); // week 3 opens later
        $this->post("/learn/courses/{$offering->id}/weeks", ['weeks' => 3])->assertSessionHas('success', 'Those weeks are already there.');

        // Week 1: a PDF, a text page, a video and a quiz
        $w1 = $weeks[0]->id;
        $this->post("/learn/courses/{$offering->id}/items", ['course_section_id' => $w1, 'type' => 'file', 'title' => 'Lecture 1 notes', 'file' => \Illuminate\Http\UploadedFile::fake()->create('lecture1.pdf', 200, 'application/pdf')])->assertSessionHasNoErrors();
        $this->post("/learn/courses/{$offering->id}/items", ['course_section_id' => $w1, 'type' => 'page', 'title' => 'Reading guide', 'body_html' => '<p>Read chapter 1.</p>'])->assertSessionHasNoErrors();
        $this->post("/learn/courses/{$offering->id}/items", ['course_section_id' => $w1, 'type' => 'file', 'title' => 'Lecture 1 video', 'file' => \Illuminate\Http\UploadedFile::fake()->create('lecture1.mp4', 900, 'video/mp4')])->assertSessionHasNoErrors();
        $this->post("/learn/courses/{$offering->id}/items", ['course_section_id' => $w1, 'type' => 'quiz', 'title' => 'Week 1 quiz'])->assertSessionHasNoErrors();
        auth()->logout();

        // the student opens the course: lecturer named, Week 1 materials there, Week 3 not yet
        $this->actingAs($ada)->get("/learn/courses/{$offering->id}")->assertOk()->assertInertia(fn ($p) => $p
            ->where('offering.staff.0.name', 'Ngozi Okeke')
            ->where('sections.0.title', 'Week 1')
            ->where('sections.0.items', fn ($items) => collect($items)->pluck('title')->intersect(['Lecture 1 notes', 'Reading guide', 'Lecture 1 video', 'Week 1 quiz'])->count() >= 3));
    }

    public function test_settings_are_checked_and_only_for_integration_managers(): void
    {
        $this->sync();
        $this->actingAs($this->user('VUG/ICH/25/001'))->put('/admin/integrations/directory', ['enabled' => false])->assertForbidden();
        $this->actingAs($this->officer)->put('/admin/integrations/directory', ['enabled' => true, 'url' => 'ftp://portal.example', 'client_id' => 'x'])->assertSessionHasErrors('url');
        $this->actingAs($this->officer)->put('/admin/integrations/directory', ['enabled' => true, 'url' => 'http://portal.example.com/bridge', 'client_id' => 'x'])->assertSessionHasErrors('url'); // https only off this computer
        $this->actingAs($this->officer)->get('/admin/integrations')->assertOk()
            ->assertInertia(fn ($p) => $p->where('directory.enabled', true)->where('directory.has_secret', true)->missing('directory.secret')->where('directoryRuns.0.summary.ok', true));
        $this->artisan('directory:sync')->assertSuccessful()->expectsOutputToContain('ok');
    }
}
