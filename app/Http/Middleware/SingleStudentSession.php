<?php

namespace App\Http\Middleware;

use App\Models\Attempt;
use App\Models\SecurityEvent;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A student is signed in on one computer at a time. Signing in again elsewhere ends the
 * older session on its next request, and an exam open in the older window stops there
 * (it has to be opened again in the new one, where it carries on with every answer).
 */
class SingleStudentSession
{
    public static function key(int $userId): string
    {
        return "login:{$userId}:current";
    }

    /** Called when a student signs in: this session becomes the only valid one. */
    public static function claim(User $user, Request $request): void
    {
        if ($user->user_type !== 'student' || ! $request->hasSession()) {
            return;
        }
        $token = Str::random(40);
        $request->session()->put('login_token', $token);
        $previous = Cache::get(self::key($user->id));
        Cache::put(self::key($user->id), $token, now()->addDays(30));

        if (config('session.driver') === 'database') {
            // the new session has no user yet at this point, so only the older ones go
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        // an exam left open elsewhere stops; opening it here resumes it with every answer kept
        $open = Attempt::where('user_id', $user->id)->whereIn('status', Attempt::OPEN)->get();
        foreach ($open as $attempt) {
            $attempt->forceFill(['token_hash' => null])->save();
            app(\App\Services\Exams\AttemptService::class)->event($attempt, 'signed_in_elsewhere', ['ip' => $request->ip()], 2);
        }

        if ($previous) {
            SecurityEvent::create(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'type' => 'session_replaced', 'ip' => $request->ip(),
                'path' => 'login', 'data' => ['open_exams' => $open->count()], 'created_at' => now()]);
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->user_type === 'student' && $request->hasSession() && ! $request->session()->has('support_session_id')) {
            $current = Cache::get(self::key($user->id));
            $mine = $request->session()->get('login_token');

            if (! $current) {
                // nothing recorded (cache cleared): this session becomes the current one
                if (! $mine) {
                    $mine = Str::random(40);
                    $request->session()->put('login_token', $mine);
                }
                Cache::put(self::key($user->id), $mine, now()->addDays(30));
            } elseif ($mine !== $current) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $message = 'You signed in on another computer, so you were signed out here.';

                return $request->expectsJson() && ! $request->header('X-Inertia')
                    ? response()->json(['message' => $message, 'reason' => 'signed_in_elsewhere'], 401)
                    : redirect('/login')->with('status', $message);
            }
        }

        return $next($request);
    }
}
