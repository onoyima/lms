<?php

namespace App\Http\Middleware;

use App\Support\Policy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff in privileged roles (policy: mfa_required_roles) must turn on two-factor sign-in
 * before they can use anything else (LMS 3, 202; Target 87, 219).
 */
class RequireTwoFactor
{
    private const ALLOWED = ['security.edit', 'two-factor.*', 'logout', 'password.*', 'user-password.*', 'appearance.*', 'profile.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! config('platform.enforce_mfa') || $user->two_factor_confirmed_at || $request->session()->get('mfa_via_sso') || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }
        $required = (array) Policy::get('mfa_required_roles', []);
        // platform administrators hold the keys to every institution's subscription: always two-factor
        if ($user->isPlatformAdmin() || ($required && $user->hasAnyRole($required))) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Turn on two-factor sign-in first.'], 403)
                : redirect()->route('security.edit')->with('warning', 'Your role requires two-factor sign-in. Turn it on to continue.');
        }

        return $next($request);
    }
}
