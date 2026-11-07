<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which institution a request belongs to, in this order:
 *  1. the host name (exams.example.edu.ng, or a campus IP listed in the tenant's domains);
 *  2. the X-Tenant header (sent by the desktop app once it has connected);
 *  3. the institution chosen at login and kept in the session;
 *  4. the default institution (single-institution installs, campus LAN).
 */
class ResolveTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The platform console belongs to no institution: nothing institution-owned can load there.
        if ($request->is('platform', 'platform/*')) {
            $this->context->set(null);

            return $next($request);
        }

        $tenant = Tenant::findByHost($request->getHost());

        if (! $tenant && ($slug = $request->header('X-Tenant'))) {
            $tenant = Tenant::where('slug', $slug)->first();
        }

        if (! $tenant && $request->hasSession() && ($id = $request->session()->get('tenant_id'))) {
            $tenant = Tenant::find($id);
        }

        if (! $tenant) {
            $tenant = self::defaultTenant();
            // reached only by falling back: the platform's own address, so its pages before sign-in show the platform's brand
            $request->attributes->set('tenant_by_default', true);
        }

        if ($tenant && ! $tenant->isActive()) {
            // Browser sessions continue so the institution administrator can sign in and settle
            // billing (SuspendedInstitutionGate limits what opens); API and desktop calls stop here.
            abort_unless($request->hasSession(), 403, 'This institution\'s access is suspended.');
            $request->attributes->set('tenant_suspended', true);
        }

        $this->context->set($tenant);

        if ($tenant && $request->hasSession()) {
            $request->session()->put('tenant_id', $tenant->id);
        }

        // A signed-in user may only act inside their own institution.
        $user = $request->user();
        if ($user && ! $user->isPlatformAdmin() && $tenant && $user->tenant_id !== $tenant->id) {
            auth()->guard('web')->logout();
            abort(403, 'This account belongs to a different institution.');
        }

        return $next($request);
    }

    /** The institution used when no address matches: PLATFORM_DEFAULT_TENANT if set, otherwise the first institution added. */
    public static function defaultTenant(): ?Tenant
    {
        $slug = (string) config('platform.default_tenant');
        if ($slug !== '') {
            return Tenant::where('slug', $slug)->first();
        }
        return Tenant::query()->orderBy('id')->first();
    }
}
