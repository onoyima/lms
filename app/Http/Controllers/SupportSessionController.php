<?php

namespace App\Http\Controllers;

use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlled support sign-in: a platform administrator, giving a reason, signs in as one of an institution's
 * users for at most 30 minutes. It is written to both audit trails, the institution's administrators are told,
 * every action in it is marked as done on behalf of support, and passwords, two-factor settings and payments
 * cannot be changed during it.
 */
class SupportSessionController extends Controller
{
    public const MINUTES = 30;

    public function __construct(private AuditLogger $audit, private TenantContext $context) {}

    /** Platform console: start. */
    public function start(Request $request, Tenant $tenant): Response
    {
        $data = $request->validate(['user_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'min:10', 'max:255']]);
        abort_unless($tenant->status === 'active' || $tenant->suspension_reason === 'non_payment', 422, 'This institution is suspended.');
        $target = $this->context->run($tenant, function () use ($data) {
            $q = User::query()->where('status', 'active');

            return $data['user_id'] ?? null ? $q->find($data['user_id']) : $q->permission('institution.manage')->orderBy('id')->first();
        });
        abort_unless($target, 422, 'No active user to sign in as.');
        $support = SupportSession::create(['platform_user_id' => $request->user()->id, 'tenant_id' => $tenant->id, 'target_user_id' => $target->id,
            'reason' => $data['reason'], 'ip' => $request->ip()]);
        $token = Str::random(48);
        Cache::put("support:$token", $support->id, 120);
        $this->audit->record('platform.support_session_requested', $tenant, ['as' => $target->id, 'reason' => $data['reason'], 'support_session' => $support->id]);

        return Inertia::location($this->tenantUrl($request, $tenant, "/support/enter/$token"));
    }

    private function tenantUrl(Request $request, Tenant $tenant, string $path): string
    {
        $domain = collect($tenant->domains ?? [])->first();
        if (! $domain) {
            return url($path);
        }
        $port = $request->getPort();

        return $request->getScheme().'://'.$domain.(in_array($port, [80, 443], true) ? '' : ":$port").$path;
    }

    /** At the institution's address: single-use link, valid for two minutes. */
    public function enter(Request $request, string $token): RedirectResponse
    {
        $id = Cache::pull("support:$token");
        $support = $id ? SupportSession::find($id) : null;
        abort_unless($support && ! $support->started_at, 403, 'This support link has expired. Start again from the platform console.');
        $tenant = Tenant::findOrFail($support->tenant_id);
        $hostTenant = Tenant::findByHost($request->getHost());
        abort_if($hostTenant && $hostTenant->id !== $tenant->id, 403, 'This support link belongs to another institution.');
        $platformUser = User::withoutGlobalScopes()->find($support->platform_user_id);
        abort_unless($platformUser?->isPlatformAdmin() && $request->user()?->id === $platformUser->id, 403, 'Only the platform administrator who started this can use it.');

        $this->context->set($tenant);
        $target = User::find($support->target_user_id);
        abort_unless($target && $target->status === 'active', 403);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        Auth::guard('web')->login($target);
        $request->session()->regenerate();
        $expires = now()->addMinutes(self::MINUTES);
        $request->session()->put(['tenant_id' => $tenant->id, 'support_session_id' => $support->id, 'support_by_name' => $platformUser->name, 'support_expires_at' => $expires->toIso8601String()]);
        $support->update(['started_at' => now(), 'expires_at' => $expires, 'ip' => $request->ip()]);

        $this->audit->record('support.session_started', $target, ['platform_user' => $platformUser->name, 'platform_user_id' => $platformUser->id, 'reason' => $support->reason, 'until' => $expires->toIso8601String()], $platformUser->id);
        Notification::send(User::permission('institution.manage')->where('status', 'active')->get(), new LmsNotice('Platform support signed in',
            "{$platformUser->name} from the platform team signed in as {$target->name} for support until ".$expires->format('H:i').". Reason: {$support->reason}", null, true, category: 'security'));

        return redirect('/dashboard')->with('success', "Support session as {$target->name}. It ends at ".$expires->format('H:i').'.');
    }

    public function leave(Request $request): RedirectResponse
    {
        $this->end($request, 'ended');

        return redirect('/login')->with('status', 'Support session ended.');
    }

    public function end(Request $request, string $how): void
    {
        $support = SupportSession::find($request->session()->get('support_session_id'));
        if ($support && ! $support->ended_at) {
            $support->update(['ended_at' => now()]);
            $this->audit->record('support.session_'.$how, $request->user(), ['support_session' => $support->id], $support->platform_user_id);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
