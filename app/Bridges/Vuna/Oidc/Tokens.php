<?php

namespace App\Bridges\Vuna\Oidc;

use App\Bridges\Vuna\Portal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sign-in codes, access tokens and ID tokens for the bridge's OpenID Connect provider.
 * Codes are single use, short-lived, bound to the client, the return address and the PKCE challenge;
 * nothing secret is ever kept in plain form (cache keys are hashes of the codes and tokens).
 */
class Tokens
{
    public function __construct(private Keys $keys, private Clients $clients, private Portal $portal) {}

    public static function issuer(): string
    {
        return rtrim((string) (config('vuna_bridge.issuer') ?: rtrim((string) config('app.url'), '/').'/bridge/vuna'), '/');
    }

    private static function key(string $kind, string $secret): string
    {
        return "vunabridge:$kind:".hash('sha256', $secret);
    }

    public function issueCode(array $client, string $redirectUri, string $subject, array $scopes, ?string $nonce, ?string $challenge, int $authTime): string
    {
        $code = Str::random(48);
        Cache::put(self::key('code', $code), [
            'client' => $client['id'], 'redirect_uri' => $redirectUri, 'sub' => $subject, 'scopes' => $scopes,
            'nonce' => $nonce, 'challenge' => $challenge, 'auth_time' => $authTime,
        ], (int) config('vuna_bridge.ttl.code'));

        return $code;
    }

    /**
     * The token endpoint. @return array{0: int, 1: array} [HTTP status, JSON body]
     */
    public function exchange(array $input, ?string $basicId = null, ?string $basicSecret = null): array
    {
        // client_secret_basic (values are form-encoded inside Basic, RFC 6749 2.3.1) or client_secret_post — not both
        if ($basicId !== null && isset($input['client_id']) && $input['client_id'] !== rawurldecode($basicId)) {
            return $this->error('invalid_client', 'Client details do not match.', 401);
        }
        $client = $basicId !== null
            ? $this->clients->authenticate(rawurldecode($basicId), rawurldecode((string) $basicSecret))
            : $this->clients->authenticate($input['client_id'] ?? null, $input['client_secret'] ?? null);
        if (! $client) {
            return $this->error('invalid_client', 'Unknown client or wrong secret.', 401);
        }

        return match ($input['grant_type'] ?? null) {
            'authorization_code' => $this->codeGrant($client, $input),
            'client_credentials' => $this->clientGrant($client, $input),
            default => $this->error('unsupported_grant_type', 'Use authorization_code or client_credentials.'),
        };
    }

    private function codeGrant(array $client, array $input): array
    {
        $code = (string) ($input['code'] ?? '');
        if ($code === '' || strlen($code) > 200) {
            return $this->error('invalid_grant', 'The code is missing.');
        }
        // one use only, even when two requests race
        if (! Cache::add(self::key('used', $code), 1, (int) config('vuna_bridge.ttl.code') + 60)) {
            Cache::forget(self::key('code', $code));

            return $this->error('invalid_grant', 'This code has already been used.');
        }
        $data = Cache::pull(self::key('code', $code));
        if (! is_array($data) || ! hash_equals((string) $data['client'], (string) $client['id'])) {
            return $this->error('invalid_grant', 'The code is not valid for this client, or it has expired.');
        }
        if (! hash_equals((string) $data['redirect_uri'], (string) ($input['redirect_uri'] ?? ''))) {
            return $this->error('invalid_grant', 'The return address does not match the sign-in request.');
        }
        if ($data['challenge'] !== null) {
            $verifier = (string) ($input['code_verifier'] ?? '');
            $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (! preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) || ! hash_equals($data['challenge'], $computed)) {
                return $this->error('invalid_grant', 'The PKCE verifier does not match.');
            }
        }
        // the person may have been withdrawn or terminated a moment ago
        $profile = $this->portal->bySubject($data['sub']);
        if (! $profile || ! $profile['active']) {
            return $this->error('invalid_grant', 'This account is no longer active.');
        }

        $now = time();
        $access = Str::random(48);
        $ttl = (int) config('vuna_bridge.ttl.access_token');
        Cache::put(self::key('access', $access), ['sub' => $data['sub'], 'client' => $client['id'], 'scopes' => $data['scopes']], $ttl);
        $idToken = $this->keys->sign(array_merge($this->claims($profile, $data['scopes']), [
            'iss' => self::issuer(), 'aud' => $client['id'], 'sub' => $data['sub'], 'iat' => $now, 'exp' => $now + (int) config('vuna_bridge.ttl.id_token'),
            'auth_time' => $data['auth_time'],
        ], $data['nonce'] !== null ? ['nonce' => $data['nonce']] : []));

        return [200, ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $ttl, 'id_token' => $idToken, 'scope' => implode(' ', $data['scopes'])]];
    }

    private function clientGrant(array $client, array $input): array
    {
        if (empty($client['directory'])) {
            return $this->error('unauthorized_client', 'This client may not read the directory.');
        }
        $requested = preg_split('/\s+/', trim((string) ($input['scope'] ?? 'directory')), -1, PREG_SPLIT_NO_EMPTY);
        if (array_diff($requested, ['directory']) !== []) {
            return $this->error('invalid_scope', 'Only the directory scope can be asked for.');
        }
        $access = Str::random(48);
        $ttl = (int) config('vuna_bridge.ttl.access_token');
        Cache::put(self::key('access', $access), ['sub' => null, 'client' => $client['id'], 'scopes' => ['directory']], $ttl);

        return [200, ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $ttl, 'scope' => 'directory']];
    }

    /** What an access token allows; null when unknown or expired. */
    public function access(?string $bearer): ?array
    {
        if (! $bearer || strlen($bearer) > 200) {
            return null;
        }
        $data = Cache::get(self::key('access', $bearer));

        return is_array($data) ? $data : null;
    }

    /** Claims about a person, limited by the scopes the application asked for. */
    public function claims(array $profile, array $scopes): array
    {
        $claims = [
            'user_type' => $profile['type'],
            'identifier' => $profile['identifier'] ?? null,
            'groups' => $profile['groups'] ?? [],
            'status' => $profile['status'] ?? null,
        ];
        if (in_array('profile', $scopes, true)) {
            $claims += [
                'name' => $profile['name'] ?? null, 'given_name' => $profile['first_name'] ?? null, 'middle_name' => $profile['middle_name'] ?? null,
                'family_name' => $profile['last_name'] ?? null, 'preferred_username' => $profile['identifier'] ?? null,
                'department_id' => $profile['department_id'] ?? null, 'faculty_id' => $profile['faculty_id'] ?? null,
                'programme_id' => $profile['programme_id'] ?? null, 'level' => $profile['level'] ?? null,
            ];
        }
        if (in_array('email', $scopes, true) && ! empty($profile['email'])) {
            // addresses come from the institution's own records
            $claims += ['email' => $profile['email'], 'email_verified' => true];
        }

        return array_filter($claims, fn ($v) => $v !== null);
    }

    private function error(string $code, string $description, int $status = 400): array
    {
        return [$status, ['error' => $code, 'error_description' => $description]];
    }
}
