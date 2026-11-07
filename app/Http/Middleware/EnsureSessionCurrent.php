<?php

namespace App\Http\Middleware;

use App\Services\Security\SessionTerminator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Refuses a session started before the person was signed out everywhere (lost privileges or account switched off). */
class EnsureSessionCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $request->hasSession() && ! $request->session()->has('support_session_id')) {
            $cutoff = Cache::get(SessionTerminator::cutoffKey($user->id));
            if ($cutoff && (float) $request->session()->get('auth_at', 0) < (float) $cutoff) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson() && ! $request->header('X-Inertia')
                    ? response()->json(['message' => 'Your access changed. Sign in again.'], 401)
                    : redirect('/login')->with('status', 'Your access changed. Please sign in again.');
            }
        }

        return $next($request);
    }
}
