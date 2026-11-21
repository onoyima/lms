<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the public demo, visitors can try almost everything, but not the things that would lock out the
 * next visitor, reach the outside world or change how the demo is reached: passwords, two-factor,
 * e-mail addresses, single sign-on, integrations and API keys, billing, branding, roles, people's
 * accounts, and maintenance. The demo's data is replaced every night anyway.
 */
class DemoGuard
{
    private const BLOCKED = [
        'settings/password', 'settings/profile', 'user/*', 'settings/two-factor*', 'two-factor*',
        'admin/sso*', 'admin/integrations*', 'admin/conferencing*', 'admin/mail*', 'admin/sms*', 'admin/maintenance*',
        'institution/billing*', 'admin/branding*', 'admin/roles*', 'admin/people/*/password*', 'admin/people/*/status*',
        'admin/people/*/delete*', 'admin/identity*', 'admin/policy*', 'my/identity*', 'apply',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();
        if (! $tenant || ! $tenant->isDemo() || $request->isMethodSafe()) {
            return $next($request);
        }

        $deleting = $request->isMethod('DELETE') && $request->is('admin/people*');
        if ($deleting || $request->is(...self::BLOCKED)) {
            $message = 'This is the public demo, so this change is switched off. Everything else works as it would for your institution.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 403);
            }

            return back()->with('error', $message);
        }

        return $next($request);
    }
}
