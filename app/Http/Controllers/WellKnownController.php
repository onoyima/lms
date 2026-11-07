<?php

namespace App\Http\Controllers;

use App\Services\Modules\ModuleRegistry;
use App\Support\ClientIdentity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /.well-known/exam-platform.json
 * Lets the desktop app, SEB launcher and web app confirm which institution a server
 * belongs to before a student signs in. The "payload" is signed with the platform's
 * Ed25519 key; clients pin the public key on first connection.
 */
class WellKnownController extends Controller
{
    public function __invoke(TenantContext $context, ModuleRegistry $modules, ClientIdentity $identity): JsonResponse
    {
        $tenant = $context->get();
        abort_unless($tenant, 404);

        $payload = [
            'version' => 1,
            'institution' => $tenant->brandPayload(),
            'endpoints' => array_values(array_unique(array_merge([url('/')], config('platform.client_endpoints')))),
            'channels' => ['web', 'desktop', 'seb'],
            'modules' => $modules->enabledFor($tenant),
            // sign-in pages the desktop app may show during single sign-on
            'sso_hosts' => \App\Models\SsoProvider::where('enabled', true)->get()->flatMap(fn ($p) => array_filter([
                parse_url((string) ($p->config['issuer'] ?? ''), PHP_URL_HOST), parse_url((string) ($p->config['idp_sso_url'] ?? ''), PHP_URL_HOST),
            ]))->unique()->values()->all(),
            'issued_at' => now()->toIso8601String(),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response()->json([
            'payload' => $json,
            'signature' => $identity->sign($json),
            'public_key' => $identity->publicKey(),
            'algorithm' => 'ed25519',
        ])->header('Cache-Control', 'no-store');
    }
}
