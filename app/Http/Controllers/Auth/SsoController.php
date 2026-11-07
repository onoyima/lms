<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Sso\OidcClient;
use App\Services\Sso\SamlClient;
use App\Services\Sso\SsoLogin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use OneLogin\Saml2\Utils as SamlUtils;
use RuntimeException;
use Throwable;

/** Single sign-on with the institution's identity provider (SAML 2.0 or OpenID Connect). */
class SsoController extends Controller
{
    public function __construct(private SsoLogin $login, private AuditLogger $audit) {}

    private function provider(string $slug): SsoProvider
    {
        return SsoProvider::where('slug', $slug)->where('enabled', true)->firstOrFail();
    }

    public function redirect(Request $request, string $slug, OidcClient $oidc, SamlClient $saml): RedirectResponse
    {
        $p = $this->provider($slug);
        try {
            if ($p->type === 'oidc') {
                $start = $oidc->start($p, route('sso.callback', $slug));
                $request->session()->put("sso.$slug", ['state' => $start['state'], 'nonce' => $start['nonce'], 'verifier' => $start['verifier'], 'at' => time()]);

                return redirect()->away($start['url']);
            }
            $auth = $saml->auth($p);
            $url = $auth->login(null, [], false, false, true);
            // the IdP posts back cross-site, where the session cookie is not sent, so the request is remembered on the server (one use, 10 minutes)
            Cache::put('saml:request:'.$auth->getLastRequestID(), $slug, now()->addMinutes(10));

            return redirect()->away($url);
        } catch (Throwable $e) {
            return $this->fail($slug, $e);
        }
    }

    public function callback(Request $request, string $slug, OidcClient $oidc): RedirectResponse
    {
        $p = $this->provider($slug);
        $flow = $request->session()->pull("sso.$slug");
        try {
            if ($p->type !== 'oidc' || ! $flow || time() - $flow['at'] > 600) {
                throw new RuntimeException('The sign-in took too long or was started elsewhere. Try again.');
            }
            if ($request->filled('error')) {
                throw new RuntimeException('Sign-in was cancelled or refused.');
            }
            if (! hash_equals($flow['state'], (string) $request->query('state'))) {
                throw new RuntimeException('The sign-in response did not match this browser. Try again.');
            }
            $claims = $oidc->finish($p, (string) $request->query('code'), route('sso.callback', $slug), $flow['verifier'], $flow['nonce']);
            $map = $p->config['claims'] ?? [];
            $user = $this->login->resolve($p, [
                'subject' => (string) $claims['sub'],
                'email' => $claims[$map['email'] ?? 'email'] ?? (filter_var($claims['preferred_username'] ?? '', FILTER_VALIDATE_EMAIL) ? $claims['preferred_username'] : null),
                'email_verified' => (bool) ($claims['email_verified'] ?? ($p->config['trust_email'] ?? false)),
                'name' => $claims[$map['name'] ?? 'name'] ?? null,
                'identifier' => ! empty($map['identifier']) ? ($claims[$map['identifier']] ?? null) : null,
                'attributes' => $claims,
            ]);
        } catch (Throwable $e) {
            return $this->fail($slug, $e);
        }

        return $this->signIn($request, $p, $user);
    }

    public function acs(Request $request, string $slug, SamlClient $saml): RedirectResponse
    {
        $p = $this->provider($slug);
        try {
            if ($p->type !== 'saml') {
                throw new RuntimeException('Not a SAML provider.');
            }
            $requestId = $this->inResponseTo((string) $request->input('SAMLResponse'));
            if (! $requestId || Cache::pull('saml:request:'.$requestId) !== $slug) {
                throw new RuntimeException('This sign-in was not started here, or it expired. Try again.');
            }
            $auth = $saml->auth($p);
            $auth->processResponse($requestId);
            if ($auth->getErrors() || ! $auth->isAuthenticated()) {
                throw new RuntimeException('The identity provider response was not valid: '.($auth->getLastErrorReason() ?? 'unknown'));
            }
            // replay protection: each assertion is accepted once
            $assertionId = $auth->getLastAssertionId();
            if (! $assertionId || ! Cache::add('saml:assertion:'.sha1($slug.$assertionId), 1, now()->addHours(8))) {
                throw new RuntimeException('This sign-in response was already used.');
            }
            $attr = $auth->getAttributes();
            $map = $p->config['attributes'] ?? [];
            $first = fn (?string $k) => $k && isset($attr[$k]) ? (string) ((array) $attr[$k])[0] : null;
            $email = $first($map['email'] ?? 'email') ?? $first('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress')
                ?? (filter_var($auth->getNameId(), FILTER_VALIDATE_EMAIL) ? $auth->getNameId() : null);
            $user = $this->login->resolve($p, [
                'subject' => $auth->getNameId(), 'email' => $email, 'email_verified' => true, // a signed assertion from the institution's own IdP
                'name' => $first($map['name'] ?? 'displayName') ?? $first('http://schemas.microsoft.com/identity/claims/displayname'),
                'identifier' => $first($map['identifier'] ?? null),
                'attributes' => $attr,
            ]);
        } catch (Throwable $e) {
            return $this->fail($slug, $e);
        }

        return $this->signIn($request, $p, $user);
    }

    private function inResponseTo(string $encoded): ?string
    {
        $xml = base64_decode($encoded, true);
        if (! $xml || strlen($xml) > 512000 || stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET); // no network access; entities are not expanded
        libxml_use_internal_errors($previous);
        $id = $ok ? $dom->documentElement?->getAttribute('InResponseTo') : '';

        return $id !== '' && preg_match('/^[A-Za-z0-9_.-]{1,100}$/', (string) $id) ? $id : null;
    }

    public function metadata(string $slug, SamlClient $saml)
    {
        $p = SsoProvider::where('slug', $slug)->where('type', 'saml')->firstOrFail();

        return response($saml->auth($p)->getSettings()->getSPMetadata(), 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    private function signIn(Request $request, SsoProvider $p, User $user): RedirectResponse
    {
        // people with their own two-factor still answer it, unless the provider already enforces it
        if ($user->two_factor_confirmed_at && ! $p->trust_idp_mfa) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }
        Auth::login($user);
        $request->session()->regenerate();
        $p->trust_idp_mfa && $request->session()->put('mfa_via_sso', true);

        return redirect()->intended(route('dashboard'));
    }

    private function fail(string $slug, Throwable $e): RedirectResponse
    {
        $this->audit->record('sso.failed', null, ['provider' => $slug, 'reason' => mb_substr($e->getMessage(), 0, 300)]);
        \App\Models\SecurityEvent::create(['tenant_id' => tenant_id(), 'type' => 'login_failed', 'ip' => request()->ip(), 'path' => 'sso/'.$slug,
            'data' => ['reason' => mb_substr($e->getMessage(), 0, 190)], 'created_at' => now()]);
        $message = $e instanceof RuntimeException ? $e->getMessage() : 'Single sign-on failed. Try again or use your password.';

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    // ── Settings (institution admins) ─────────────────────────────────────────

    public function index(): Response
    {
        return Inertia::render('admin/sso', [
            'providers' => SsoProvider::orderBy('name')->get()->map(fn ($p) => [
                ...$p->only(['id', 'type', 'name', 'slug', 'enabled', 'auto_create', 'default_user_type', 'default_role', 'link_by_email', 'trust_idp_mfa', 'role_rules', 'type_attribute']),
                // secrets are never sent back; only whether one is set
                'config' => collect($p->config)->except(['client_secret'])->all() + ['has_secret' => ! empty($p->config['client_secret'])],
                'urls' => $p->type === 'saml'
                    ? ['Entity ID / metadata' => url("/sso/{$p->slug}/metadata"), 'Reply (ACS) URL' => url("/sso/{$p->slug}/acs")]
                    : ['Redirect URI' => url("/sso/{$p->slug}/callback")],
            ]),
            'roles' => array_values(array_filter(\App\Support\Roles::names(), fn ($r) => ! \App\Support\Roles::privileged($r))),
            'roleLabels' => \App\Support\Roles::labels(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, SsoProvider $provider): RedirectResponse
    {
        return $this->save($request, $provider);
    }

    private function save(Request $request, ?SsoProvider $provider): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:saml,oidc'], 'name' => ['required', 'string', 'max:80'],
            'slug' => ['required', 'alpha_dash', 'max:40', Rule::unique('sso_providers', 'slug')->where('tenant_id', tenant_id())->ignore($provider?->id)],
            'enabled' => ['boolean'], 'auto_create' => ['boolean'], 'link_by_email' => ['boolean'], 'trust_idp_mfa' => ['boolean'],
            // a provider can never hand out administrator roles on its own
            'default_user_type' => ['required', 'in:student,staff'], 'default_role' => ['nullable', Rule::in(array_filter(\App\Support\Roles::names(), fn ($r) => ! \App\Support\Roles::privileged($r)))],
            'role_rules' => ['nullable', 'array', 'max:50'], 'role_rules.*.attribute' => ['required', 'string', 'max:200'],
            'role_rules.*.value' => ['required', 'string', 'max:200'], 'role_rules.*.role' => ['required', Rule::in(array_filter(\App\Support\Roles::names(), fn ($r) => ! \App\Support\Roles::privileged($r)))],
            'type_attribute' => ['nullable', 'string', 'max:80'],
            'config' => ['required', 'array'],
            'config.issuer' => ['required_if:type,oidc', 'nullable', 'url', 'max:300'], 'config.client_id' => ['required_if:type,oidc', 'nullable', 'string', 'max:300'],
            'config.client_secret' => ['nullable', 'string', 'max:500'], 'config.scopes' => ['nullable', 'string', 'max:200'], 'config.trust_email' => ['nullable', 'boolean'],
            'config.idp_entity_id' => ['required_if:type,saml', 'nullable', 'string', 'max:300'], 'config.idp_sso_url' => ['required_if:type,saml', 'nullable', 'url', 'max:500'],
            'config.idp_x509' => ['required_if:type,saml', 'nullable', 'string', 'max:10000'],
            'config.claims' => ['nullable', 'array'], 'config.claims.*' => ['nullable', 'string', 'max:200'],
            'config.attributes' => ['nullable', 'array'], 'config.attributes.*' => ['nullable', 'string', 'max:300'],
        ]);
        $config = array_intersect_key($data['config'], array_flip(['issuer', 'client_id', 'client_secret', 'scopes', 'trust_email', 'idp_entity_id', 'idp_sso_url', 'idp_x509', 'claims', 'attributes']));
        if ($data['type'] === 'oidc' && ! OidcClient::safeUrl($config['issuer'] ?? '')) {
            return back()->withErrors(['config.issuer' => 'The issuer must use https.']);
        }
        if ($data['type'] === 'saml') {
            $config['idp_x509'] = SamlUtils::formatCert($config['idp_x509']);
            if (! @openssl_x509_read("-----BEGIN CERTIFICATE-----\n".chunk_split($config['idp_x509'], 64, "\n")."-----END CERTIFICATE-----")) {
                return back()->withErrors(['config.idp_x509' => 'That is not a valid certificate.']);
            }
            if (! str_starts_with((string) ($config['idp_sso_url'] ?? ''), 'https://')) {
                return back()->withErrors(['config.idp_sso_url' => 'The sign-in address must use https.']);
            }
        }
        // keep the stored secret unless a new one is typed
        if (empty($config['client_secret']) && $provider) {
            $config['client_secret'] = $provider->config['client_secret'] ?? null;
        }
        $p = $provider ?? new SsoProvider;
        $data['role_rules'] = array_values($data['role_rules'] ?? []);
        $p->fill(['config' => $config] + $data)->save();
        $this->audit->record($provider ? 'sso.updated' : 'sso.created', $p, ['slug' => $p->slug, 'enabled' => $p->enabled]);

        return back()->with('success', 'Sign-in provider saved.');
    }

    public function destroy(SsoProvider $provider): RedirectResponse
    {
        $this->audit->record('sso.deleted', $provider, ['slug' => $provider->slug]);
        $provider->delete();

        return back()->with('success', 'Provider removed.');
    }
}
