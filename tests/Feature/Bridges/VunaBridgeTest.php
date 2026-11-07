<?php

namespace Tests\Feature\Bridges;

use App\Bridges\Vuna\Demo\DemoPortal;
use App\Services\Sso\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The portal bridge on its own: who is active, the running semester, the directory API and portal sign-in. */
class VunaBridgeTest extends TestCase
{
    use RefreshDatabase, UsesDemoPortal;

    private const REDIRECT = 'http://localhost/sso/portal/callback';

    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDemoPortal();
        $this->verifier = str_repeat('v3rifier-', 6);
    }

    private function authorizeQuery(array $extra = []): array
    {
        return array_merge([
            'client_id' => 'exams-lms', 'redirect_uri' => self::REDIRECT, 'response_type' => 'code', 'scope' => 'openid email profile',
            'state' => 'st4te', 'nonce' => 'n0nce', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], $extra);
    }

    private function sealed(string $html): string
    {
        $this->assertSame(1, preg_match('/name="request" value="([^"]+)"/', $html, $m));

        return html_entity_decode($m[1]);
    }

    /** Sign in on the bridge page and return the code it hands back. */
    private function signIn(string $type, string $login, string $password, array $query = []): \Illuminate\Testing\TestResponse
    {
        $form = $this->get('/bridge/vuna/authorize?'.http_build_query($this->authorizeQuery($query)));
        $form->assertOk()->assertSee('Sign in to Exams &amp; LMS', false);

        return $this->post('/bridge/vuna/authorize', ['request' => $this->sealed($form->getContent()), 'action' => 'login', 'account_type' => $type, 'login' => $login, 'password' => $password]);
    }

    private function codeFrom(\Illuminate\Testing\TestResponse $r): string
    {
        $r->assertOk()->assertSee('Signed in');
        $this->assertSame(1, preg_match('/url=([^"]+)"/', $r->getContent(), $m));
        $url = html_entity_decode($m[1]);
        $this->assertStringStartsWith(self::REDIRECT.'?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('st4te', $q['state']);

        return $q['code'];
    }

    private function exchange(string $code, ?string $verifier = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Basic '.base64_encode('exams-lms:'.self::BRIDGE_SECRET))
            ->post('/bridge/vuna/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier ?? $this->verifier]);
    }

    public function test_the_directory_lists_only_people_who_are_active_now_and_the_running_semester(): void
    {
        $this->getJson('/bridge/vuna/api/v1/students')->assertStatus(401);
        $token = $this->directoryToken();
        $api = fn (string $path) => $this->withToken($token)->getJson('/bridge/vuna/api/v1/'.$path)->assertOk()->json();

        $term = $api('term');
        $this->assertSame('directory-bridge/1', $term['contract']);
        // the running semester, and the last one before it (students' previous courses)
        $this->assertCount(2, $term['semesters']);
        $semesters = collect($term['semesters'])->keyBy('id');
        $this->assertSame(['id' => 3, 'running' => true, 'session' => '2025/2026', 'semester' => 'First semester', 'programme' => 'Undergraduate'],
            array_intersect_key($semesters[3], array_flip(['id', 'running', 'session', 'semester', 'programme'])));
        $this->assertSame(['running' => false, 'session' => '2024/2025'], array_intersect_key($semesters[1], array_flip(['running', 'session'])));

        // active: normal and on probation; not deferred (3) or suspended (4); 5 is normal through the newer studentship_id column
        $students = collect($api('students')['data'])->keyBy('id');
        $this->assertSame([1, 2, 5], $students->keys()->all());
        $this->assertSame(300, $students[2]['level']);          // stored as level id 3
        $this->assertSame(1, $students[2]['faculty_id']);       // taken from the department
        $this->assertSame('VUG/BCS/25/101', $students[5]['identifier']); // matric only on the academic record
        $this->assertSame(100, $students[5]['level']);          // "100L"
        $this->assertNull($students[5]['email']);
        $this->assertContains('Class Representative', $students[1]['groups']);
        $this->assertArrayNotHasKey('password', $students[1]);

        // staff: Tunde's appointment ended; the Dean's removed Exam Officer role is not reported
        $staff = collect($api('staff')['data'])->keyBy('id');
        $this->assertSame([1, 2, 3, 5], $staff->keys()->all());
        $this->assertContains('HOD', $staff[1]['groups']);
        $this->assertContains('teaching', $staff[1]['groups']);
        $this->assertContains('Dean', $staff[2]['groups']);
        $this->assertNotContains('Exam Officer', $staff[2]['groups']);
        $this->assertNotContains('teaching', $staff[2]['groups']);
        $this->assertSame(['Exam Officer', 'Level Adviser'], collect($staff[3]['roles'])->pluck('name')->all());
        $this->assertSame('segun.ade@vuna.test', $staff[5]['email']); // personal address when the work one is empty
        $this->assertSame('VUNA/ST/0042', $staff[1]['identifier']);
        $this->assertSame('Dr.', $staff[1]['title']);

        // registrations: registered (not deleted) courses, running semester only, active students only
        $regs = collect($api('registrations')['data'])->map(fn ($r) => $r['semester_id'].' '.$r['student_id'].' '.$r['course_code'].' '.$r['category'])->sort()->values()->all();
        $this->assertSame(['1 1 CSC 101 core', '3 1 CHM 101 core', '3 1 GST 101 core', '3 2 CSC 101 carryover', '3 2 CSC 301 core', '3 2 ENG 211 elective', '3 5 CSC 101 core', '3 5 GST 101 core'], $regs);

        // teaching from both portal tables, running semester only, not from staff who have left
        $teaching = $api('teaching');
        $this->assertNull($teaching['next']);
        $this->assertSame('0:1', $api('teaching?limit=1')['next']);
        $this->assertCount(3, $api('teaching?limit=3&after=0:1')['data']); // the rest of the first table and on into the second
        $pairs = collect($teaching['data'])->map(fn ($t) => $t['semester_id'].' '.$t['staff_id'].' '.$t['course_code'].' '.$t['role'])->sort()->values()->all();
        $this->assertSame(['1 1 CHM 201 lecturer', '3 1 CHM 101 lecturer', '3 1 GST 101 lecturer', '3 3 CSC 101 lecturer', '3 3 CSC 301 coordinator'], $pairs);

        $this->assertSame([100], collect($api('applicants')['data'])->pluck('id')->all()); // this session, not yet admitted
        $courses = collect($api('courses')['data'])->keyBy('id');
        $this->assertSame('GST 101', $courses[7]['code']); // "gst  101" is the same course
        $this->assertSame(200, $courses[2]['level']);

        $statuses = $api('statuses?type=student&ids=3,4,99');
        $this->assertSame(['active' => false, 'status' => 'deferred'], $statuses['data']['3']);
        $this->assertSame(['active' => false, 'status' => 'suspended'], $statuses['data']['4']);
        $this->assertSame('removed', $statuses['data']['99']['status']);
        $this->assertSame('terminated', $api('statuses?type=staff&ids=4')['data']['4']['status']);

        // cursor paging
        $page = $api('students?limit=2');
        $this->assertSame(2, $page['next']);
        $this->assertSame([5], collect($api('students?limit=2&after=2')['data'])->pluck('id')->all());
        $this->assertSame(['active_semesters' => 1, 'active_students' => 3, 'active_staff' => 4], $api('health')['counts']);
    }

    public function test_staff_students_and_applicants_sign_in_with_their_own_portal_accounts(): void
    {
        $code = $this->codeFrom($this->signIn('staff', 'VUNA/ST/0042', DemoPortal::STAFF_PASSWORD));
        $token = $this->exchange($code)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $jwks = $this->getJson('/bridge/vuna/jwks')->assertOk()->json('keys');
        [$header, $claims] = Jwt::verify($token['id_token'], fn ($kid) => collect($jwks)->firstWhere('kid', $kid));
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('http://localhost/bridge/vuna', $claims['iss']);
        $this->assertSame('exams-lms', $claims['aud']);
        $this->assertSame('staff:1', $claims['sub']);
        $this->assertSame('n0nce', $claims['nonce']);
        $this->assertSame('staff', $claims['user_type']);
        $this->assertSame('VUNA/ST/0042', $claims['identifier']);
        $this->assertSame('ngozi.okeke@vuna.test', $claims['email']);
        $this->assertTrue($claims['email_verified']);
        $this->assertContains('HOD', $claims['groups']);
        $this->assertSame('staff:1', $this->withToken($token['access_token'])->getJson('/bridge/vuna/userinfo')->assertOk()->json('sub'));
        // the sign-in token cannot read the directory
        $this->withToken($token['access_token'])->getJson('/bridge/vuna/api/v1/students')->assertStatus(401);

        // a code works once
        $this->exchange($code)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

        // already signed in: asked whether to continue
        $this->get('/bridge/vuna/authorize?'.http_build_query($this->authorizeQuery(['prompt' => 'select_account'])))->assertOk()->assertSee('Continue as Ngozi');
        // no prompt: straight back with a new code
        $this->get('/bridge/vuna/authorize?'.http_build_query($this->authorizeQuery()))->assertRedirectContains(self::REDIRECT.'?code=');
        $this->get('/bridge/vuna/logout')->assertOk()->assertSee('signed out');
        $this->get('/bridge/vuna/authorize?'.http_build_query($this->authorizeQuery(['prompt' => 'none'])))->assertRedirectContains('error=login_required');

        // students by matric number (even when it is only on the academic record), registration number or email
        $student = $this->exchange($this->codeFrom($this->signIn('student', 'VUG/BCS/25/101', DemoPortal::STUDENT_PASSWORD)))->assertOk()->json('id_token');
        $this->assertSame('student:5', $this->claimsOf($student)['sub']);
        $this->get('/bridge/vuna/logout');
        $this->codeFrom($this->signIn('student', 'ADA.OKAFOR@students.vuna.test', DemoPortal::STUDENT_PASSWORD));
        $this->get('/bridge/vuna/logout');

        // applicants of this session
        $applicant = $this->exchange($this->codeFrom($this->signIn('applicant', 'grace.obi@example.com', DemoPortal::APPLICANT_PASSWORD)))->json('id_token');
        $c = $this->claimsOf($applicant);
        $this->assertSame(['applicant:100', 'applicant', 'APP/2025/0451'], [$c['sub'], $c['user_type'], $c['identifier']]);
        $this->get('/bridge/vuna/logout');
    }

    public function test_wrong_details_inactive_accounts_and_bad_requests_are_refused(): void
    {
        $this->signIn('staff', 'ngozi.okeke@vuna.test', 'wrong')->assertStatus(422)->assertSee('not right');
        $this->signIn('student', 'ngozi.okeke@vuna.test', DemoPortal::STAFF_PASSWORD)->assertStatus(422); // staff password on the student form
        $this->signIn('student', 'VUG/BEN/22/009', DemoPortal::STUDENT_PASSWORD)->assertStatus(403)->assertSee('deferred');
        $this->signIn('student', 'VUG/ICH/23/020', DemoPortal::STUDENT_PASSWORD)->assertStatus(403)->assertSee('suspended');
        $this->signIn('staff', 'tunde.bakare@vuna.test', DemoPortal::STAFF_PASSWORD)->assertStatus(403);
        $this->signIn('applicant', 'henry.uche@example.com', DemoPortal::APPLICANT_PASSWORD)->assertStatus(403)->assertSee('admissions office');

        // five wrong tries lock that login for a while
        for ($i = 0; $i < 5; $i++) {
            $this->signIn('staff', 'funke.adeyemi@vuna.test', 'nope'.$i);
        }
        $this->signIn('staff', 'funke.adeyemi@vuna.test', DemoPortal::STAFF_PASSWORD)->assertStatus(429)->assertSee('Too many attempts');

        // unknown return address: shown here, never redirected
        $this->get('/bridge/vuna/authorize?'.http_build_query($this->authorizeQuery(['redirect_uri' => 'https://evil.example/cb'])))->assertStatus(400)->assertSee('not registered');
        $this->get('/bridge/vuna/authorize?'.http_build_query($this->authorizeQuery(['code_challenge' => ''])))->assertRedirectContains('error=invalid_request');
        $this->get('/bridge/vuna/authorize?'.http_build_query($this->authorizeQuery(['scope' => 'email'])))->assertRedirectContains('error=invalid_scope');

        // wrong PKCE verifier, wrong client secret, tampered sign-in request
        $code = $this->codeFrom($this->signIn('staff', 'ibrahim.musa@vuna.test', DemoPortal::STAFF_PASSWORD));
        $this->exchange($code, str_repeat('x', 50))->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        $this->withHeader('Authorization', 'Basic '.base64_encode('exams-lms:wrong'))->post('/bridge/vuna/token', ['grant_type' => 'client_credentials'])->assertStatus(401);
        $this->post('/bridge/vuna/authorize', ['request' => 'tampered', 'account_type' => 'staff', 'login' => 'x', 'password' => 'y'])->assertStatus(400);
    }

    public function test_the_bridge_never_opens_a_database_on_another_computer_and_is_off_by_default(): void
    {
        config(['vuna_bridge.db' => ['driver' => 'mysql', 'host' => 'portal-db.example.com', 'database' => 'portal', 'username' => 'x', 'password' => 'y']]);
        (new \App\Bridges\Vuna\VunaBridgeServiceProvider($this->app))->registerConnection();
        $this->assertNull(config('database.connections.vuna'));
        $this->getJson('/bridge/vuna/.well-known/openid-configuration')->assertStatus(503);
        $this->artisan('vuna:bridge-check')->assertFailed();
        $this->artisan('vuna:demo-portal')->assertFailed();

        config(['vuna_bridge.enabled' => false]);
        $this->getJson('/bridge/vuna/.well-known/openid-configuration')->assertNotFound();
    }

    public function test_a_remote_portal_database_is_only_used_when_switched_on_and_only_ever_read(): void
    {
        $remote = ['driver' => 'mysql', 'host' => 'c00000.example-host.net', 'port' => '3306', 'database' => 'portal', 'username' => 'reader', 'password' => 'x', 'ssl_ca' => null];
        config(['vuna_bridge.db' => $remote, 'vuna_bridge.allow_remote' => false]);
        (new \App\Bridges\Vuna\VunaBridgeServiceProvider($this->app))->registerConnection();
        $this->assertNull(config('database.connections.vuna'));
        $this->assertStringContainsString('VUNA_DB_ALLOW_REMOTE', (string) config('vuna_bridge.blocked'));

        config(['vuna_bridge.allow_remote' => true]);
        (new \App\Bridges\Vuna\VunaBridgeServiceProvider($this->app))->registerConnection();
        $this->assertNull(config('vuna_bridge.blocked'));
        $this->assertSame('c00000.example-host.net', config('database.connections.vuna.host'));
        $this->assertContains('SET SESSION TRANSACTION READ ONLY', config('database.connections.vuna.options'));

        // and whatever the database allows, the bridge itself refuses anything but reads
        $this->bootDemoPortal();
        config(['vuna_bridge.testing_allow_writes' => false]);
        $portal = app(\App\Bridges\Vuna\Portal::class);
        $this->assertSame(3, $portal->counts()['active_students']);
        foreach ([fn () => $portal->db()->table('students')->where('id', 1)->update(['fname' => 'X']), fn () => $portal->db()->table('course_regs')->delete(),
            fn () => $portal->db()->table('users')->insert(['email' => 'x@y.z', 'user_type' => 7, 'password' => 'x']), fn () => $portal->db()->statement('DROP TABLE students')] as $write) {
            try {
                $write();
                $this->fail('A write reached the portal database.');
            } catch (\RuntimeException $e) {
                $this->assertSame('The portal bridge only reads from the portal database.', $e->getMessage());
            }
        }
        $this->assertSame('Ada', $portal->db()->table('students')->where('id', 1)->value('fname'));
    }

    public function test_discovery_describes_the_provider(): void
    {
        $d = $this->getJson('/bridge/vuna/.well-known/openid-configuration')->assertOk()->json();
        $this->assertSame('http://localhost/bridge/vuna', $d['issuer']);
        $this->assertSame('http://localhost/bridge/vuna/token', $d['token_endpoint']);
        $this->assertSame(['S256'], $d['code_challenge_methods_supported']);
        $this->artisan('vuna:bridge-check')->assertSuccessful()->expectsOutputToContain('The bridge can read the portal.');
    }

    private function claimsOf(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }
}
