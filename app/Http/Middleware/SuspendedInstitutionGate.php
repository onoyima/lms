<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * While an institution is suspended (unpaid, by request or for abuse) nobody can use it,
 * except that its administrators can still sign in, see the billing page and pay.
 */
class SuspendedInstitutionGate
{
    private const ALWAYS = ['home', 'apply.*', 'login', 'login.*', 'logout', 'password.*', 'two-factor.*', 'verification.*', 'user-password.*', 'webhooks.*', 'verify', 'sso.*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->get('tenant_suspended') || $request->routeIs(...self::ALWAYS)) {
            return $next($request);
        }
        $user = $request->user();
        $admin = $user && $user->can('billing.manage');
        if ($admin && $request->routeIs('billing.*', 'profile.*', 'security.*', 'appearance.*')) {
            return $next($request);
        }
        if ($admin && $request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect()->route('billing.index');
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'This institution\'s access is suspended.'], 403);
        }

        $tenant = app(\App\Support\Tenancy\TenantContext::class)->get();
        $reason = $tenant?->suspension_reason;

        return Inertia::render('suspended', [
            'institution' => $tenant?->name,
            'reason' => $reason === 'non_payment' ? 'The subscription has not been paid.' : null,
            'signedIn' => (bool) $user,
        ])->toResponse($request)->setStatusCode(403);
    }
}
