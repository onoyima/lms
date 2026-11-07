<?php

namespace App\Services\Directory;

use App\Services\Integrations\Connectors;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to an institution's directory bridge (contract "directory-bridge/1"): a client-credentials
 * token, then read-only GETs, following cursors page by page. Any institution can put its own bridge
 * in front of its records system as long as it speaks this contract.
 */
class DirectoryClient
{
    public const CONTRACT = 'directory-bridge/1';

    private ?string $token = null;

    public function __construct(private string $base, private string $clientId, private string $secret) {}

    public static function fromSettings(array $c): self
    {
        $base = rtrim(trim((string) ($c['url'] ?? '')), '/');
        if ($base === '' || ! self::safeUrl($base)) {
            throw new DirectoryException('The directory address must be https (or http on this computer during local development).');
        }
        if (($c['client_id'] ?? '') === '' || ($c['secret'] ?? '') === '') {
            throw new DirectoryException('Give the client ID and client secret issued by the portal bridge.');
        }

        return new self($base, (string) $c['client_id'], (string) $c['secret']);
    }

    /**
     * The bridge may live on the campus network next to the portal. https is required except on this
     * computer during local development; loopback, link-local and cloud metadata addresses are refused
     * in production, and private campus addresses only when PLATFORM_ALLOW_PRIVATE_TARGETS is on.
     */
    public static function safeUrl(string $url): bool
    {
        $p = parse_url($url);
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if ($host === '' || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return app()->environment('local', 'testing') && in_array($p['scheme'] ?? '', ['http', 'https'], true);
        }

        return ($p['scheme'] ?? '') === 'https' && Connectors::safeHost($host);
    }

    private function token(bool $fresh = false): string
    {
        if ($this->token && ! $fresh) {
            return $this->token;
        }
        $res = $this->send(fn () => Http::timeout(20)->acceptJson()->asForm()
            ->withBasicAuth(rawurlencode($this->clientId), rawurlencode($this->secret))
            ->post($this->base.'/token', ['grant_type' => 'client_credentials', 'scope' => 'directory']));
        if ($res->status() === 401) {
            throw new DirectoryException('The bridge did not accept the client ID or secret.');
        }
        if (! $res->successful() || ! is_string($res->json('access_token'))) {
            throw new DirectoryException('The bridge did not give an access token ('.$res->status().').');
        }

        return $this->token = $res->json('access_token');
    }

    /** One GET; a token that expired mid-sync is renewed once. */
    public function get(string $path, array $query = []): array
    {
        $url = $this->base.'/api/v1/'.ltrim($path, '/');
        $call = fn (string $token) => $this->send(fn () => Http::timeout(60)->acceptJson()->withToken($token)->get($url, $query));
        $res = $call($this->token());
        if ($res->status() === 401) {
            $res = $call($this->token(true));
        }
        if (! $res->successful()) {
            $why = $res->json('error_description') ?: $res->json('error') ?: 'HTTP '.$res->status();
            throw new DirectoryException("The bridge could not answer $path: ".mb_substr((string) $why, 0, 200));
        }
        $body = $res->json();
        if (! is_array($body) || ($body['contract'] ?? null) !== self::CONTRACT) {
            throw new DirectoryException("The bridge's answer to $path is not in the expected format (".self::CONTRACT.').');
        }

        return $body;
    }

    /** One POST (used to check a portal ID and password). */
    public function post(string $path, array $data): array
    {
        $url = $this->base.'/api/v1/'.ltrim($path, '/');
        $call = fn (string $token) => $this->send(fn () => Http::timeout(15)->acceptJson()->asForm()->withToken($token)->post($url, $data));
        $res = $call($this->token());
        if ($res->status() === 401) {
            $res = $call($this->token(true));
        }
        $body = $res->json();
        if (! $res->successful() || ! is_array($body) || ($body['contract'] ?? null) !== self::CONTRACT) {
            throw new DirectoryException('The university portal cannot check sign-ins right now.');
        }

        return $body;
    }

    /** Every row of a paged list, one page at a time. */
    public function each(string $path, callable $page, int $limit = 500): int
    {
        $after = null;
        $count = 0;
        for ($i = 0; $i < 100000; $i++) {
            $body = $this->get($path, array_filter(['limit' => $limit, 'after' => $after], fn ($v) => $v !== null));
            $rows = (array) ($body['data'] ?? []);
            $rows && $page($rows);
            $count += count($rows);
            $next = $body['next'] ?? null;
            if ($next === null || $next === '' || (string) $next === (string) $after) {
                return $count;
            }
            $after = $next;
        }
        throw new DirectoryException("Too many pages from $path.");
    }

    private function send(callable $request): Response
    {
        try {
            return retry(2, $request, 500, fn ($e) => $e instanceof ConnectionException);
        } catch (ConnectionException) {
            throw new DirectoryException('The bridge cannot be reached at '.$this->base.'.');
        }
    }
}
