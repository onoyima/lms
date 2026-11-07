<?php

namespace App\Bridges\Vuna\Oidc;

/** Applications registered with the bridge (config vuna_bridge.clients). */
class Clients
{
    public function find(?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        foreach ((array) config('vuna_bridge.clients') as $client) {
            if (($client['id'] ?? null) !== null && hash_equals((string) $client['id'], $id)) {
                return $client;
            }
        }

        return null;
    }

    /** Client id and secret both right; a client without a secret can never authenticate. */
    public function authenticate(?string $id, ?string $secret): ?array
    {
        $client = $this->find($id);
        $expected = (string) ($client['secret'] ?? '');
        if (! $client || $expected === '' || $secret === null || ! hash_equals($expected, $secret)) {
            return null;
        }

        return $client;
    }

    /** Return addresses must match a registered one exactly — no prefixes, no wildcards. */
    public function redirectAllowed(array $client, ?string $uri): bool
    {
        return $uri !== null && $uri !== '' && in_array($uri, (array) ($client['redirect_uris'] ?? []), true);
    }

    public function logoutAllowed(?string $uri): bool
    {
        if (! $uri) {
            return false;
        }
        foreach ((array) config('vuna_bridge.clients') as $client) {
            if (in_array($uri, (array) ($client['post_logout_redirect_uris'] ?? []), true)) {
                return true;
            }
        }

        return false;
    }
}
