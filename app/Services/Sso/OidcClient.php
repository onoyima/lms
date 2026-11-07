<?php

namespace App\Services\Sso;

use App\Models\SsoProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OpenID Connect sign-in (Microsoft Entra ID, Google Workspace, Keycloak, …): authorization
 * code flow with PKCE, state and nonce; ID token signature, issuer, audience, expiry and
 * nonce all checked here.
 */
class OidcClient
{
    public static function safeUrl(string $url): bool
    {
        $p = parse_url($url);
        $local = in_array($p['host'] ?? '', ['localhost', '127.0.0.1'], true) && app()->environment('local', 'testing');

        return ($p['scheme'] ?? '') === 'https' || ($local && ($p['scheme'] ?? '') === 'http');
    }

    public function discovery(SsoProvider $p): array
    {
        $issuer = rtrim((string) ($p->config['issuer'] ?? ''), '/');
        if (! self::safeUrl($issuer)) {
            throw new RuntimeException('The issuer address must use https.');
        }

        return Cache::remember('oidc:disc:'.sha1($issuer), 3600, function () use ($issuer) {
            $doc = Http::timeout(10)->acceptJson()->get($issuer.'/.well-known/openid-configuration')->throw()->json();
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'issuer'] as $k) {
                if (empty($doc[$k]) || ($k !== 'issuer' && ! self::safeUrl($doc[$k]))) {
                    throw new RuntimeException("The provider's configuration is missing $k.");
                }
            }

            return $doc;
        });
    }

    /** @return array{url: string, state: string, nonce: string, verifier: string} */
    public function start(SsoProvider $p, string $redirectUri): array
    {
        $d = $this->discovery($p);
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $query = http_build_query([
            'response_type' => 'code', 'client_id' => $p->config['client_id'], 'redirect_uri' => $redirectUri,
            'scope' => trim($p->config['scopes'] ?? 'openid email profile'), 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);

        return ['url' => $d['authorization_endpoint'].(str_contains($d['authorization_endpoint'], '?') ? '&' : '?').$query, 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier];
    }

    /** Exchange the code and return verified ID-token claims. */
    public function finish(SsoProvider $p, string $code, string $redirectUri, string $verifier, string $nonce): array
    {
        $d = $this->discovery($p);
        $res = Http::timeout(15)->asForm()->acceptJson()
            ->withBasicAuth($p->config['client_id'], $p->config['client_secret'] ?? '')
            ->post($d['token_endpoint'], ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $verifier]);
        if (! $res->successful() || ! is_string($res->json('id_token'))) {
            throw new RuntimeException('The sign-in provider refused the request.');
        }
        [, $claims] = Jwt::verify($res->json('id_token'), fn (?string $kid, string $alg) => $this->key($d['jwks_uri'], $kid, $alg));

        $aud = (array) ($claims['aud'] ?? []);
        $now = time();
        match (true) {
            rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($d['issuer'], '/') => throw new RuntimeException('Token issuer does not match.'),
            ! in_array($p->config['client_id'], $aud, true) => throw new RuntimeException('Token was not issued for this application.'),
            count($aud) > 1 && ($claims['azp'] ?? null) !== $p->config['client_id'] => throw new RuntimeException('Token authorised party does not match.'),
            ! isset($claims['exp']) || $claims['exp'] < $now - 60 => throw new RuntimeException('Token has expired.'),
            isset($claims['iat']) && $claims['iat'] > $now + 300 => throw new RuntimeException('Token is from the future.'),
            ! hash_equals($nonce, (string) ($claims['nonce'] ?? '')) => throw new RuntimeException('Token nonce does not match.'),
            empty($claims['sub']) => throw new RuntimeException('Token has no subject.'),
            default => null,
        };

        return $claims;
    }

    private function key(string $jwksUri, ?string $kid, string $alg): ?array
    {
        $find = function (bool $fresh) use ($jwksUri, $kid, $alg) {
            $cacheKey = 'oidc:jwks:'.sha1($jwksUri);
            $fresh && Cache::forget($cacheKey);
            $keys = Cache::remember($cacheKey, 3600, fn () => Http::timeout(10)->acceptJson()->get($jwksUri)->throw()->json('keys') ?? []);
            foreach ($keys as $k) {
                $type = str_starts_with($alg, 'ES') ? 'EC' : 'RSA';
                if (($k['kty'] ?? null) === $type && ($kid === null || ($k['kid'] ?? null) === $kid) && (($k['use'] ?? 'sig') === 'sig')) {
                    return $k;
                }
            }

            return null;
        };

        return $find(false) ?? $find(true); // keys rotate: look again once
    }
}
