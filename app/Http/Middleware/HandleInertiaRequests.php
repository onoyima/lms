<?php

namespace App\Http\Middleware;

use App\Services\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => fn () => \App\Support\Platform\PlatformSettings::brand()['name'],
            'platform' => fn () => \App\Support\Platform\PlatformSettings::brand(),
            'auth' => [
                'user' => $request->user(),
                'permissions' => fn () => $request->user()
                    ? ($request->user()->isPlatformAdmin() ? [] : $request->user()->getAllPermissions()->pluck('name'))
                    : [],
                'isPlatformAdmin' => fn () => (bool) $request->user()?->isPlatformAdmin(),
                // exams office, or staff with exams assigned to them: shows the exam board in the menu
                // examiner accounts made for one panel exam: their exam (the menu shows only that)
                'examinerExam' => fn () => $request->user()?->user_type === \App\Models\ExamPanelAccount::USER_TYPE
                    ? \App\Models\ExamPanelAccount::with('exam:id,title')->where('user_id', $request->user()->id)->first()?->exam?->only(['id', 'title']) : null,
                'examBoard' => fn () => $request->user() && ! $request->user()->isPlatformAdmin() && tenant() && \App\Support\Exams\ExamAccess::hasAny($request->user()),
            ],
            // the institution in view; the public landing page belongs to the platform itself
            'tenant' => fn () => self::showsPlatform($request) ? null : tenant()?->brandPayload(),
            'roles' => fn () => $request->user() && ! $request->user()->isPlatformAdmin() ? $request->user()->getRoleNames() : [],
            'modules' => fn () => app(ModuleRegistry::class)->enabledFor(tenant()),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'maintenance' => fn () => $request->user() ? \App\Support\Maintenance::upcoming() : [],
            'announcements' => fn () => \App\Models\PlatformAnnouncement::forUser($request->user()),
            'impersonation' => fn () => $request->hasSession() && $request->session()->has('support_session_id') ? [
                'by' => $request->session()->get('support_by_name'), 'expires_at' => $request->session()->get('support_expires_at'),
            ] : null,
            'notificationsUnread' => fn () => \App\Http\Controllers\NotificationController::unreadCount($request->user()),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** Pages that belong to the platform rather than an institution: the public site, and sign-in at the platform's own address. */
    public static function showsPlatform(Request $request): bool
    {
        return $request->routeIs('home') || $request->is('platform', 'platform/*') || (! $request->user() && $request->attributes->get('tenant_by_default'));
    }
}
