<?php

namespace App\Http\Middleware;

use App\Support\Platform\PlatformSettings;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request limits per signed-in person, per address (for people not signed in), per API key and per
 * institution, so one user, script or institution cannot slow the service for everyone else.
 * The figures are platform settings. Exam-room traffic has its own tighter, per-attempt limits.
 */
class TenantRateLimit
{
    /** Routes with their own per-attempt / per-device limiter (a campus behind one address must not be throttled as one). */
    private const OWN_LIMITS = ['api/exam/*', 'livekit/*', 'api/livekit/*', 'phone/*', 'up', 'webhooks/*', 'build/*'];

    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...self::OWN_LIMITS)) {
            return $next($request);
        }
        $s = PlatformSettings::all();
        $checks = [];
        if ($user = $request->user()) {
            $checks['u:'.$user->id] = (int) $s['rate_user_per_minute'];
        } elseif ($token = $request->bearerToken()) {
            $checks['k:'.hash('sha256', $token)] = (int) $s['rate_key_per_minute'];
        } else {
            $checks['ip:'.$request->ip()] = (int) $s['rate_ip_per_minute'];
        }
        if ($tid = $this->context->id()) {
            $checks['t:'.$tid] = (int) $s['rate_tenant_per_minute'];
        }
        foreach ($checks as $key => $max) {
            if ($max > 0 && RateLimiter::tooManyAttempts('rl:'.$key, $max)) {
                $retry = RateLimiter::availableIn('rl:'.$key);

                return $request->expectsJson() && ! $request->header('X-Inertia')
                    ? response()->json(['message' => 'Too many requests. Try again in a moment.', 'error' => 'rate_limited'], 429)->header('Retry-After', (string) $retry)
                    : response('Too many requests. Try again in a moment.', 429)->header('Retry-After', (string) $retry);
            }
        }
        foreach ($checks as $key => $max) {
            if ($max > 0) {
                RateLimiter::hit('rl:'.$key, 60);
            }
        }

        return $next($request);
    }
}
