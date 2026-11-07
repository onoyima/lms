<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SupportSessionController;
use App\Models\SupportSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits of a support sign-in: it ends on time (or when ended from the console), and the person's own
 * secrets and the institution's money cannot be touched while support is signed in as them.
 */
class SupportSessionGuard
{
    private const BLOCKED = ['settings/password', 'settings/profile', 'user/*', 'institution/billing/*', 'admin/integrations/keys*', 'support/enter/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! ($id = $request->session()->get('support_session_id'))) {
            return $next($request);
        }
        $support = SupportSession::find($id);
        if (! $support || ! $support->live()) {
            app(SupportSessionController::class)->end($request, 'expired');

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => 'The support session has ended.'], 401)
                : redirect('/login')->with('status', 'The support session has ended.');
        }
        if (! $request->isMethod('GET') && ! $request->is('support/leave') && $request->is(...self::BLOCKED)) {
            abort(403, 'Not allowed during a support session: passwords, two-factor settings, account deletion, API keys and payments stay with the account holder.');
        }

        return $next($request);
    }
}
