<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform administrators run the service (institutions, plans, subscriptions, payments). They
 * are not members of any institution and never see institution data: outside the platform
 * console they can only manage their own account and sign out. Institution users never reach
 * the platform console.
 */
class EnforcePlatformBoundary
{
    /** Account pages a platform administrator may use anywhere. */
    private const OWN_ACCOUNT = ['logout', 'profile.*', 'security.*', 'password.*', 'user-password.*', 'two-factor.*', 'appearance.*', 'verification.*', 'home', 'apply.*', 'verify', 'support.enter'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $platformPath = $request->is('platform', 'platform/*');

        if ($user?->isPlatformAdmin() && ! $platformPath && ! $request->routeIs(...self::OWN_ACCOUNT)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Platform administrators cannot open institution data.'], 403)
                : redirect('/platform');
        }

        return $next($request);
    }
}
