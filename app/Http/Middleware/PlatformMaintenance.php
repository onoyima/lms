<?php

namespace App\Http\Middleware;

use App\Support\Platform\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-wide maintenance switched on from the console. Platform administrators keep working; sign-in,
 * payment notices from the payment provider and health checks still answer. Nothing is disabled in the browser.
 */
class PlatformMaintenance
{
    private const OPEN = ['login', 'logout', 'two-factor-challenge', 'platform', 'platform/*', 'up', 'webhooks/*', 'api/conferencing/*', 'conferencing/theme/*', 'conferencing/slides/*', 'billing/webhook', 'build/*', 'storage/*', 'favicon.ico'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! PlatformSettings::get('maintenance_mode') || $request->is(...self::OPEN) || $request->user()?->isPlatformAdmin()) {
            return $next($request);
        }
        $message = (string) (PlatformSettings::get('maintenance_message') ?: 'The service is being updated and will be back shortly.');
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message, 'error' => 'maintenance'], 503)->header('Retry-After', '600');
        }

        return Inertia::render('maintenance', ['message' => $message])->toResponse($request)->setStatusCode(503)->header('Retry-After', '600');
    }
}
