<?php

namespace Tests\Feature\Identity;

use App\Models\SsoIdentity;
use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Sso\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private const ISS = 'https://login.example-idp.test/tenant/v2.0';

    private \OpenSSLAsymmetricKey $key;

    private array $jwk;

    private ?string $idToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $d = openssl_pkey_get_details($this->key)['rsa'];
        $b = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $this->jwk = ['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'n' => $b($d['n']), 'e' => $b($d['e'])];
        // one fake provider per test; the token endpoint hands back whatever the test prepared
        Http::fake([
            'login.example-idp.test/tenant/v2.0/.well-known/openid-configuration' => Http::response(['issuer' => self::ISS, 'authorization_endpoint' => 'https://login.example-idp.test/authorize',
                'token_endpoint' => 'https://login.example-idp.test/token', 'jwks_uri' => 'https://login.example-idp.test/keys']),
            'login.example-idp.test/keys' => fn () => Http::response(['keys' => [$this->jwk]]),
            'login.example-idp.test/token' => fn () => Http::response(['id_token' => $this->idToken, 'access_token' => 'x']),
        ]);
    }

    private function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function token(array $claims, ?\OpenSSLAsymmetricKey $key = null, string $alg = 'RS256'): string
    {
        $head = $this->b64(json_encode(['alg' => $alg, 'kid' => 'k1', 'typ' => 'JWT']));
        $body = $this->b64(json_encode($claims));
        openssl_sign("$head.$body", $sig, $key ?? $this->key, OPENSSL_ALGO_SHA256);

        return "$head.$body.".$this->b64($sig);
    }

    private function provider(array $extra = []): SsoProvider
    {
        return SsoProvider::create(array_merge(['type' => 'oidc', 'name' => 'University account', 'slug' => 'uni', 'enabled' => true, 'link_by_email' => true,
            'config' => ['issuer' => self::ISS, 'client_id' => 'veritas-app', 'client_secret' => 's3cret', 'scopes' => 'openid email profile']], $extra));
    }

    /** Start the flow, fake the provider, and come back with the given claims. */
    private function signIn(array $claims, ?callable $tamper = null)
    {
        $start = $this->get('/sso/uni');
        $start->assertRedirect();
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('S256', $q['code_challenge_method']);
        $claims += ['iss' => self::ISS, 'aud' => 'veritas-app', 'exp' => time() + 300, 'iat' => time(), 'nonce' => $q['nonce']];
        $token = $this->token($claims);
        $tamper && $token = $tamper($token);
        $this->idToken = $token;

        return $this->get('/sso/uni/callback?code=abc&state='.$q['state']);
    }

    public function test_oidc_sign_in_links_by_verified_email_and_remembers_the_subject(): void
    {
        $this->setUpPlatform();
        $this->provider();
        $student = User::factory()->create(['email' => 'ada@uni.edu.ng', 'user_type' => 'student']);

        $this->get('/login')->assertInertia(fn ($p) => $p->where('sso.0.slug', 'uni'));
        $this->signIn(['sub' => 'idp-123', 'email' => 'ada@uni.edu.ng', 'email_verified' => true])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($student);
        $this->assertDatabaseHas('sso_identities', ['subject' => 'idp-123', 'user_id' => $student->id]);
        $this->assertDatabaseHas('security_events', ['type' => 'login_sso', 'user_id' => $student->id]);
    }

    public function test_forged_expired_or_misdirected_tokens_are_refused(): void
    {
        $this->setUpPlatform();
        $this->provider();
        User::factory()->create(['email' => 'ada@uni.edu.ng']);
        $base = ['sub' => 'idp-1', 'email' => 'ada@uni.edu.ng', 'email_verified' => true];
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $cases = [
            'wrong key' => [$base, fn ($t) => $this->token(json_decode(Jwt::b64(explode('.', $t)[1]), true), $other)],
            'alg none' => [$base, fn ($t) => $this->b64('{"alg":"none","kid":"k1"}').'.'.explode('.', $t)[1].'.'],
            'expired' => [$base + ['exp' => time() - 3600], null],
            'other audience' => [$base + ['aud' => 'someone-else'], null],
            'other issuer' => [$base + ['iss' => 'https://evil.test'], null],
            'bad nonce' => [$base + ['nonce' => 'replayed'], null],
            'unverified email' => [['email_verified' => false] + $base, null],
        ];
        foreach ($cases as $name => [$claims, $tamper]) {
            $this->signIn($claims, $tamper)->assertRedirect('/login');
            $this->assertGuest();
            $this->assertNotNull(session('errors'), $name);
        }
        $this->assertSame(0, SsoIdentity::count());
    }

    public function test_state_must_match_and_unknown_people_are_only_created_when_allowed(): void
    {
        $this->setUpPlatform();
        $p = $this->provider();
        $start = $this->get('/sso/uni');
        $this->get('/sso/uni/callback?code=abc&state=wrong')->assertRedirect('/login');
        $this->assertGuest();

        // unknown person, creation off
        $this->signIn(['sub' => 'new-1', 'email' => 'new@uni.edu.ng', 'email_verified' => true])->assertRedirect('/login');
        $this->assertGuest();

        // creation on, but only for allowed email domains
        $this->tenant->update(['settings' => ['policy' => ['email_domains' => ['uni.edu.ng']]]]);
        tenant()?->refresh();
        $p->update(['auto_create' => true, 'default_user_type' => 'student', 'default_role' => 'student']);
        $this->signIn(['sub' => 'new-2', 'email' => 'x@gmail.com', 'email_verified' => true])->assertRedirect('/login');
        $this->signIn(['sub' => 'new-3', 'email' => 'new@uni.edu.ng', 'email_verified' => true, 'name' => 'New Student'])->assertRedirect('/dashboard');
        $u = User::where('email', 'new@uni.edu.ng')->firstOrFail();
        $this->assertTrue($u->hasRole('student'));
        $this->assertFalse($u->hasRole('institution_admin'));
    }

    public function test_directory_groups_give_the_institutions_own_roles_but_never_privileged_ones(): void
    {
        $this->setUpPlatform();
        $this->tenant->update(['settings' => ['policy' => ['email_domains' => ['uni.edu.ng']]]]);
        tenant()?->refresh();
        // a role this institution made up itself
        $this->actingAs($this->officer)->post('/admin/roles', ['label' => 'Faculty exams coordinator', 'permissions' => ['exams.manage', 'questions.view']])->assertSessionHasNoErrors();
        auth()->logout();
        $p = $this->provider(['auto_create' => true, 'default_user_type' => 'student', 'default_role' => null, 'type_attribute' => 'affiliation',
            'role_rules' => [['attribute' => 'groups', 'value' => 'Exams Office', 'role' => 'faculty_exams_coordinator'], ['attribute' => 'groups', 'value' => 'Lecturers', 'role' => 'lecturer']]]);

        $this->signIn(['sub' => 'st-1', 'email' => 'ada@uni.edu.ng', 'email_verified' => true, 'name' => 'Ada', 'affiliation' => ['staff', 'member'], 'groups' => ['exams office', 'All Staff']])->assertRedirect('/dashboard');
        $u = User::where('email', 'ada@uni.edu.ng')->firstOrFail();
        $this->assertSame('staff', $u->user_type);
        $this->assertTrue($u->hasRole('faculty_exams_coordinator'));
        $this->assertFalse($u->hasRole('lecturer'));
        $this->assertTrue($u->can('exams.manage'));
        auth()->logout();

        // later the directory adds them to Lecturers: the role arrives at the next sign-in
        $this->signIn(['sub' => 'st-1', 'email' => 'ada@uni.edu.ng', 'email_verified' => true, 'groups' => ['Lecturers']])->assertRedirect('/dashboard');
        $this->assertTrue($u->fresh()->hasRole('lecturer'));
        auth()->logout();

        // rules can never hand out roles that control settings, people, roles, integrations or billing
        $this->actingAs($this->officer)->put("/admin/sso/{$p->id}", ['type' => 'oidc', 'name' => 'Uni', 'slug' => 'uni', 'default_user_type' => 'staff',
            'role_rules' => [['attribute' => 'groups', 'value' => 'IT', 'role' => 'ict_admin']],
            'config' => ['issuer' => self::ISS, 'client_id' => 'veritas-app']])->assertSessionHasErrors('role_rules.0.role');
    }

    public function test_suspended_accounts_and_two_factor_users(): void
    {
        $this->setUpPlatform();
        $this->provider();
        User::factory()->create(['email' => 'gone@uni.edu.ng', 'status' => 'suspended']);
        $this->signIn(['sub' => 's1', 'email' => 'gone@uni.edu.ng', 'email_verified' => true])->assertRedirect('/login');
        $this->assertGuest();

        $mfa = User::factory()->create(['email' => 'mfa@uni.edu.ng']);
        $mfa->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()])->save();
        $this->signIn(['sub' => 's2', 'email' => 'mfa@uni.edu.ng', 'email_verified' => true])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_settings_are_admin_only_and_secrets_never_come_back(): void
    {
        $this->setUpPlatform();
        $p = $this->provider();
        $student = User::factory()->create();
        $student->assignRole('student');
        $this->actingAs($student)->get('/admin/sso')->assertForbidden();

        $this->actingAs($this->officer)->get('/admin/sso')->assertOk()->assertInertia(fn ($page) => $page->missing('providers.0.config.client_secret')->where('providers.0.config.has_secret', true));
        $this->actingAs($this->officer)->put("/admin/sso/{$p->id}", ['type' => 'oidc', 'name' => 'Uni', 'slug' => 'uni', 'default_user_type' => 'staff', 'default_role' => 'institution_admin',
            'config' => ['issuer' => self::ISS, 'client_id' => 'veritas-app']])->assertSessionHasErrors('default_role');
        $this->actingAs($this->officer)->put("/admin/sso/{$p->id}", ['type' => 'oidc', 'name' => 'Uni', 'slug' => 'uni', 'default_user_type' => 'staff',
            'config' => ['issuer' => 'http://insecure.test', 'client_id' => 'veritas-app']])->assertSessionHasErrors('config.issuer');
        $this->actingAs($this->officer)->put("/admin/sso/{$p->id}", ['type' => 'oidc', 'name' => 'Uni', 'slug' => 'uni', 'default_user_type' => 'staff', 'enabled' => true,
            'config' => ['issuer' => self::ISS, 'client_id' => 'veritas-app']])->assertSessionHasNoErrors();
        $this->assertSame('s3cret', $p->fresh()->config['client_secret']); // kept when left empty
        $this->assertStringNotContainsString('s3cret', (string) \DB::table('sso_providers')->value('config')); // encrypted at rest
    }

    public function test_saml_metadata_and_unsolicited_responses(): void
    {
        $this->setUpPlatform();
        $cert = "MIIC" . str_repeat('A', 10);
        SsoProvider::create(['type' => 'saml', 'name' => 'ADFS', 'slug' => 'adfs', 'enabled' => true,
            'config' => ['idp_entity_id' => 'https://adfs.test/adfs/services/trust', 'idp_sso_url' => 'https://adfs.test/adfs/ls/', 'idp_x509' => $cert]]);

        $this->get('/sso/adfs/metadata')->assertOk()->assertHeader('content-type', 'application/samlmetadata+xml')->assertSee('/sso/adfs/acs', false);
        // a response nobody asked for (no CSRF token needed, but it must answer a request we made)
        $forged = base64_encode('<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" ID="_x" InResponseTo="_never_issued"/>');
        $this->post('/sso/adfs/acs', ['SAMLResponse' => $forged])->assertRedirect('/login');
        $this->assertGuest();
        $xxe = base64_encode('<!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><r InResponseTo="&x;"/>');
        $this->post('/sso/adfs/acs', ['SAMLResponse' => $xxe])->assertRedirect('/login');
        $this->assertGuest();
    }
}
