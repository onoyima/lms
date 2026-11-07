<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration API keys: "Authorization: Bearer vk_<prefix>_<secret>".
 * Only a SHA-256 of the secret is stored; keys carry scopes, an optional IP allow-list
 * and an expiry, and set the institution for the request (Target 88, 100, 217).
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $raw = (string) $request->bearerToken();
        if (! preg_match('/^vk_([A-Za-z0-9]{8})_([A-Za-z0-9]{40})$/', $raw, $m)) {
            return $this->deny($request, 'missing_or_malformed_key');
        }
        $key = DB::table('api_keys')->where('prefix', $m[1])->first();
        if (! $key || ! hash_equals($key->key_hash, hash('sha256', $m[2]))) {
            return $this->deny($request, 'unknown_key');
        }
        if ($key->revoked_at || ($key->expires_at && now()->greaterThan($key->expires_at))) {
            return $this->deny($request, 'revoked_or_expired', $key->tenant_id);
        }
        $ips = json_decode($key->allowed_ips ?? 'null', true);
        if ($ips && ! \Symfony\Component\HttpFoundation\IpUtils::checkIp((string) $request->ip(), $ips)) {
            return $this->deny($request, 'ip_not_allowed', $key->tenant_id);
        }
        if (! in_array($scope, json_decode($key->scopes, true) ?: [], true)) {
            return $this->deny($request, 'scope_missing:'.$scope, $key->tenant_id, 403);
        }

        $tenant = Tenant::cached($key->tenant_id);
        abort_unless($tenant && $tenant->isActive(), 403);
        app(TenantContext::class)->set($tenant);
        $request->attributes->set('api_key_id', $key->id);
        // the subscription's monthly API allowance (counted per institution)
        try {
            app(\App\Services\Billing\Entitlements::class)->countApiCall($tenant);
        } catch (\App\Services\Billing\CapacityExceeded $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'capacity_exceeded', 'limit' => 'api_requests_monthly'], 429)
                ->header('Retry-After', (string) now()->diffInSeconds(now()->endOfMonth()->addSecond(), true));
        }
        DB::table('api_keys')->where('id', $key->id)->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMinute()))->update(['last_used_at' => now()]);

        return $next($request);
    }

    private function deny(Request $request, string $reason, ?int $tenantId = null, int $status = 401): Response
    {
        DB::table('security_events')->insert([
            'tenant_id' => $tenantId, 'type' => 'api_key_rejected', 'ip' => $request->ip(), 'path' => substr($request->path(), 0, 255),
            'data' => json_encode(['reason' => $reason, 'agent' => substr((string) $request->userAgent(), 0, 200)]), 'created_at' => now(),
        ]);

        return response()->json(['message' => $status === 403 ? 'This key is not allowed to do that.' : 'Invalid API key.'], $status);
    }
}
