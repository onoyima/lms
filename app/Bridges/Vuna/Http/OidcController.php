<?php

namespace App\Bridges\Vuna\Http;

use App\Bridges\Vuna\Oidc\Clients;
use App\Bridges\Vuna\Oidc\Keys;
use App\Bridges\Vuna\Oidc\Tokens;
use App\Bridges\Vuna\Portal;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bridge's OpenID Connect provider. Staff sign in with their portal staff account, students with
 * their student account and applicants with their applicant account; the platform receives a signed ID token
 * saying who they are, what kind of account it is, and their portal roles (HOD, Dean, Exam Officer, ...).
 */
class OidcController extends Controller
{
    /** A real bcrypt hash, so a sign-in for an unknown account takes as long as one for a known account. */
    public const DUMMY_HASH = '$2y$12$bYEycggxmtQgerIldbDfjunUYHecHbXRgCE94cZVYiqJibszB2zW2';

    private const TYPES = ['student' => 'Student', 'staff' => 'Staff', 'applicant' => 'Applicant'];

    public function __construct(private Portal $portal, private Clients $clients, private Tokens $tokens, private Keys $keys) {}

    public function discovery(): JsonResponse
    {
        $iss = Tokens::issuer();

        return response()->json([
            'issuer' => $iss,
            'authorization_endpoint' => "$iss/authorize",
            'token_endpoint' => "$iss/token",
            'userinfo_endpoint' => "$iss/userinfo",
            'jwks_uri' => "$iss/jwks",
            'end_session_endpoint' => "$iss/logout",
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'client_credentials'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'email', 'profile', 'directory'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'prompt_values_supported' => ['none', 'login', 'select_account'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'name', 'given_name', 'middle_name', 'family_name', 'preferred_username',
                'email', 'email_verified', 'user_type', 'identifier', 'groups', 'status', 'department_id', 'faculty_id', 'programme_id', 'level'],
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function jwks(): JsonResponse
    {
        return response()->json($this->keys->jwks())->header('Cache-Control', 'public, max-age=300');
    }

    public function authorize(Request $request): Response
    {
        $pending = $this->pendingFrom($request->query());
        if ($pending instanceof Response) {
            return $pending;
        }
        try {
            $current = $this->current($request);
        } catch (\Throwable $e) {
            return $this->unavailable($e);
        }

        if (in_array('none', $pending['prompt'], true)) {
            return $current ? redirect()->away($this->finishUrl($pending, $current, (int) $request->session()->get('vuna_bridge.auth_time')))
                : redirect()->away($this->errorUrl($pending, 'login_required', 'Sign-in is needed.'));
        }
        if (! $current || in_array('login', $pending['prompt'], true)) {
            return $this->form($pending);
        }
        if (in_array('select_account', $pending['prompt'], true)) {
            return $this->chooser($pending, $current);
        }

        return redirect()->away($this->finishUrl($pending, $current, (int) $request->session()->get('vuna_bridge.auth_time')));
    }

    public function login(Request $request): Response
    {
        try {
            $raw = Crypt::decrypt((string) $request->input('request'));
        } catch (DecryptException) {
            return $this->message('The sign-in request was not valid. Go back to the application and start again.', 400);
        }
        if (! is_array($raw) || ($raw['at'] ?? 0) < time() - 1800) {
            return $this->message('The sign-in page was open too long. Go back to the application and start again.', 400);
        }
        $pending = $this->pendingFrom($raw['q'] ?? []);
        if ($pending instanceof Response) {
            return $pending;
        }
        $action = (string) $request->input('action', 'login');

        try {
            if ($action === 'switch') {
                $request->session()->forget(['vuna_bridge.sub', 'vuna_bridge.auth_time']);

                return $this->form($pending);
            }
            if ($action === 'continue') {
                $current = $this->current($request);

                return $current ? $this->continuePage($this->finishUrl($pending, $current, (int) $request->session()->get('vuna_bridge.auth_time'))) : $this->form($pending);
            }

            $data = $request->validate([
                'account_type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
                'login' => ['required', 'string', 'max:190'],
                'password' => ['required', 'string', 'max:200'],
            ]);
        } catch (\Illuminate\Validation\ValidationException) {
            return $this->form($pending, 'Choose your account type and enter your sign-in details.', $request->only('account_type', 'login'));
        } catch (\Throwable $e) {
            return $this->unavailable($e);
        }

        $key = 'vunabridge-login:'.sha1(mb_strtolower(trim($data['login'])).'|'.$data['account_type']);
        $ipKey = 'vunabridge-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $wait = max(RateLimiter::availableIn($key), RateLimiter::availableIn($ipKey));

            return $this->form($pending, "Too many attempts. Try again in $wait seconds.", $request->only('account_type', 'login'), 429);
        }

        try {
            $account = $this->portal->account($data['account_type'], $data['login']);
        } catch (\Throwable $e) {
            return $this->unavailable($e);
        }
        $hash = is_string($account['hash'] ?? null) && $account['hash'] !== '' ? $account['hash'] : self::DUMMY_HASH;
        $ok = password_verify($data['password'], $hash) && $hash !== self::DUMMY_HASH && $account !== null && $account['profile'] !== null;
        if (! $ok) {
            RateLimiter::hit($key, 300);
            RateLimiter::hit($ipKey, 60);

            return $this->form($pending, 'Those sign-in details are not right. Check the account type, your ID or email, and your portal password.', $request->only('account_type', 'login'), 422);
        }
        RateLimiter::clear($key);
        $profile = $account['profile'];
        if (! $profile['active']) {
            Log::info('vuna-bridge: inactive account refused', ['subject' => $profile['subject'], 'status' => $profile['status']]);

            return $this->form($pending, $data['account_type'] === 'applicant'
                ? 'Your application is not open for this session. Please contact the admissions office.'
                : 'Your portal account is not active for exams and learning (status: '.$profile['status'].'). Please contact the registry.', $request->only('account_type', 'login'), 403);
        }

        $request->session()->regenerate();
        $request->session()->put(['vuna_bridge.sub' => $profile['subject'], 'vuna_bridge.auth_time' => time()]);

        return $this->continuePage($this->finishUrl($pending, $profile, time()));
    }

    public function token(Request $request): JsonResponse
    {
        [$id, $secret] = [null, null];
        $header = (string) $request->header('Authorization');
        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded === false || ! str_contains($decoded, ':')) {
                return $this->noStore(response()->json(['error' => 'invalid_client', 'error_description' => 'Malformed client credentials.'], 401));
            }
            [$id, $secret] = explode(':', $decoded, 2);
        }
        try {
            [$status, $body] = $this->tokens->exchange($request->only(['grant_type', 'code', 'redirect_uri', 'code_verifier', 'client_id', 'client_secret', 'scope']), $id, $secret);
        } catch (\Throwable $e) {
            report($e);
            [$status, $body] = [503, ['error' => 'temporarily_unavailable', 'error_description' => 'The portal cannot be reached right now.']];
        }
        $response = response()->json($body, $status);
        $status === 401 && $response->header('WWW-Authenticate', 'Basic realm="bridge"');

        return $this->noStore($response);
    }

    public function userinfo(Request $request): JsonResponse
    {
        $access = $this->tokens->access($request->bearerToken());
        if (! $access || empty($access['sub'])) {
            return response()->json(['error' => 'invalid_token'], 401)->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }
        try {
            $profile = $this->portal->bySubject($access['sub']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'temporarily_unavailable'], 503);
        }
        if (! $profile || ! $profile['active']) {
            return response()->json(['error' => 'invalid_token', 'error_description' => 'This account is no longer active.'], 401)->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }

        return $this->noStore(response()->json(['sub' => $access['sub']] + $this->tokens->claims($profile, $access['scopes'])));
    }

    public function logout(Request $request): Response
    {
        $request->session()->forget(['vuna_bridge.sub', 'vuna_bridge.auth_time']);
        $to = (string) $request->query('post_logout_redirect_uri', '');
        if ($this->clients->logoutAllowed($to)) {
            $state = (string) $request->query('state', '');

            return redirect()->away($to.($state !== '' ? (str_contains($to, '?') ? '&' : '?').http_build_query(['state' => mb_substr($state, 0, 500)]) : ''));
        }

        return $this->message('You have signed out of the portal sign-in. You can close this window.');
    }

    // ------------------------------------------------------------------ helpers

    /** Check an authorization request. Problems with the client or return address are shown here, never redirected. */
    private function pendingFrom(array $q): array|Response
    {
        $q = array_map(fn ($v) => is_string($v) ? $v : '', array_intersect_key($q, array_flip(['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'nonce', 'code_challenge', 'code_challenge_method', 'prompt', 'login_hint'])));
        $client = $this->clients->find($q['client_id'] ?? null);
        if (! $client || ! $this->clients->redirectAllowed($client, $q['redirect_uri'] ?? null)) {
            return $this->message('This application is not registered with the portal sign-in, or its return address is not allowed.', 400);
        }
        $pending = [
            'q' => $q, 'client' => $client, 'redirect_uri' => $q['redirect_uri'], 'state' => mb_substr($q['state'] ?? '', 0, 500),
            'nonce' => ($q['nonce'] ?? '') !== '' ? mb_substr($q['nonce'], 0, 500) : null,
            'scopes' => array_values(array_unique(preg_split('/\s+/', trim($q['scope'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))),
            'prompt' => preg_split('/\s+/', trim($q['prompt'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
            'challenge' => $q['code_challenge'] ?? '',
        ];
        if (($q['response_type'] ?? '') !== 'code') {
            return redirect()->away($this->errorUrl($pending, 'unsupported_response_type', 'Only the code flow is supported.'));
        }
        if (! in_array('openid', $pending['scopes'], true) || array_diff($pending['scopes'], ['openid', 'email', 'profile']) !== []) {
            return redirect()->away($this->errorUrl($pending, 'invalid_scope', 'Ask for openid, and optionally email and profile.'));
        }
        if (! preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $pending['challenge']) || ($q['code_challenge_method'] ?? '') !== 'S256') {
            return redirect()->away($this->errorUrl($pending, 'invalid_request', 'PKCE with S256 is required.'));
        }

        return $pending;
    }

    /** The person already signed in to the bridge in this browser, if still active. */
    private function current(Request $request): ?array
    {
        $sub = $request->session()->get('vuna_bridge.sub');
        $at = (int) $request->session()->get('vuna_bridge.auth_time');
        if (! $sub || $at < time() - 60 * (int) config('vuna_bridge.ttl.session')) {
            $request->session()->forget(['vuna_bridge.sub', 'vuna_bridge.auth_time']);

            return null;
        }
        $profile = $this->portal->bySubject($sub);
        if (! $profile || ! $profile['active']) {
            $request->session()->forget(['vuna_bridge.sub', 'vuna_bridge.auth_time']);

            return null;
        }

        return $profile;
    }

    private function finishUrl(array $pending, array $profile, int $authTime): string
    {
        $code = $this->tokens->issueCode($pending['client'], $pending['redirect_uri'], $profile['subject'], $pending['scopes'], $pending['nonce'], $pending['challenge'], $authTime);

        return $this->withQuery($pending['redirect_uri'], array_filter(['code' => $code, 'state' => $pending['state']], fn ($v) => $v !== ''));
    }

    private function errorUrl(array $pending, string $error, string $description): string
    {
        return $this->withQuery($pending['redirect_uri'], array_filter(['error' => $error, 'error_description' => $description, 'state' => $pending['state']], fn ($v) => $v !== ''));
    }

    private function withQuery(string $url, array $params): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params);
    }

    private function sealed(array $pending): string
    {
        return Crypt::encrypt(['q' => $pending['q'], 'at' => time()]);
    }

    private function form(array $pending, ?string $error = null, array $old = [], int $status = 200): Response
    {
        $type = $old['account_type'] ?? null;
        if (! isset(self::TYPES[$type])) {
            $type = 'student';
        }

        return $this->noStore(response()->view('bridges.vuna.login', [
            'institution' => config('vuna_bridge.name'), 'client' => $pending['client']['name'] ?? 'the application', 'sealed' => $this->sealed($pending),
            'types' => self::TYPES, 'type' => $type, 'login' => $old['login'] ?? $pending['q']['login_hint'] ?? '', 'error' => $error,
        ], $status));
    }

    private function chooser(array $pending, array $current): Response
    {
        return $this->noStore(response()->view('bridges.vuna.choose', [
            'institution' => config('vuna_bridge.name'), 'client' => $pending['client']['name'] ?? 'the application', 'sealed' => $this->sealed($pending),
            'person' => $current, 'kind' => self::TYPES[$current['type']] ?? ucfirst($current['type']),
        ]));
    }

    /** After a form post, continue with a plain link (a redirect after a form post can be blocked by the browser's form-action rules). */
    private function continuePage(string $url): Response
    {
        return $this->noStore(response()->view('bridges.vuna.continue', ['institution' => config('vuna_bridge.name'), 'url' => $url]));
    }

    private function message(string $text, int $status = 200): Response
    {
        return $this->noStore(response()->view('bridges.vuna.message', ['institution' => config('vuna_bridge.name'), 'text' => $text], $status));
    }

    private function unavailable(\Throwable $e): Response
    {
        report($e);

        return $this->message('The portal cannot be reached right now. Please try again in a few minutes.', 503);
    }

    private function noStore(Response $r): Response
    {
        $r->headers->set('Cache-Control', 'no-store');
        $r->headers->set('Pragma', 'no-cache');

        return $r;
    }
}
