<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use App\Support\ClientIdentity;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(ClientIdentity::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // mail sent before the sender is configured carries the platform's name, not the framework's
        // (MAIL_FROM_NAME="${APP_NAME}" in .env counts as not configured: the platform's brand is used)
        if (in_array(config('mail.from.name'), [null, '', 'Laravel', 'Example', config('app.name')], true)) {
            try {
                config(['mail.from.name' => \App\Support\Platform\PlatformSettings::brand()['name']]);
            } catch (\Throwable) {
                // database not ready yet (first install)
            }
        }

        $this->configureDefaults();
        // queued jobs run as the institution that queued them (permissions, scopes, mail server)
        \Illuminate\Support\Facades\Queue::createPayloadUsing(fn () => ['tenant_id' => tenant_id()]);
        $tenantStack = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function ($e) use (&$tenantStack) {
            $ctx = app(\App\Support\Tenancy\TenantContext::class);
            $tenantStack[] = [$e->job->getJobId(), $ctx->get()];
            $id = $e->job->payload()['tenant_id'] ?? null;
            $ctx->set($id ? \App\Models\Tenant::find($id) : null);
        });
        $restore = function ($e) use (&$tenantStack) {
            if ($tenantStack && end($tenantStack)[0] === $e->job->getJobId()) {
                app(\App\Support\Tenancy\TenantContext::class)->set(array_pop($tenantStack)[1]);
            }
        };
        foreach ([\Illuminate\Queue\Events\JobProcessed::class, \Illuminate\Queue\Events\JobExceptionOccurred::class, \Illuminate\Queue\Events\JobFailed::class] as $ev) {
            \Illuminate\Support\Facades\Event::listen($ev, $restore);
        }
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, [\App\Listeners\MailDeliveryLog::class, 'sending']);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSent::class, [\App\Listeners\MailDeliveryLog::class, 'sent']);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Notifications\Events\NotificationFailed::class, [\App\Listeners\MailDeliveryLog::class, 'failed']);
        \Illuminate\Support\Facades\Auth::provider('tenant_users', fn ($app, array $config) => new \App\Support\Auth\TenantUserProvider($app['hash'], $config['model']));

        $this->rateLimits();
        $this->securityLogging();

        // Platform staff may do anything; everyone else needs the permission through a role.
        // Platform administrators get no institution permissions at all (they run the service, not institutions).

        // @can('module', 'live_proctoring') in Blade / Gate::allows('module', ...) in PHP
        Gate::define('module', fn (?User $user, string $module) => app(ModuleRegistry::class)->isEnabled(tenant(), $module));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Password rules come from the institution's policy (LMS 202). "uncompromised" is not used
        // because it calls an outside service; campus servers may have no internet.
        Password::defaults(function (): ?Password {
            if (! app()->isProduction()) {
                return null;
            }
            $p = \App\Support\Policy::all();
            $rule = Password::min(max(8, (int) $p['password_min']))->letters();
            $p['password_mixed_case'] && $rule->mixedCase();
            $p['password_numbers'] && $rule->numbers();
            $p['password_symbols'] && $rule->symbols();

            return $rule;
        });
    }

    /** Rate limits (Target 88, 217-218). Exam API is keyed by attempt, not IP, so a whole hall behind one NAT is fine. */
    protected function rateLimits(): void
    {
        $attemptKey = fn ($request) => (string) (\App\Services\Exams\AttemptToken::attemptId((string) $request->bearerToken()) ?? $request->ip());
        \Illuminate\Support\Facades\RateLimiter::for('exam-api', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(300)->by('a:'.$attemptKey($request)));
        \Illuminate\Support\Facades\RateLimiter::for('exam-evidence', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('e:'.$attemptKey($request)));
        \Illuminate\Support\Facades\RateLimiter::for('integration', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by('k:'.substr((string) $request->bearerToken(), 0, 11).$request->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('exam-identity', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(12)->by('i:'.$attemptKey($request)));
        \Illuminate\Support\Facades\RateLimiter::for('face-enrol', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(6)->by('fe:'.($request->user()?->id ?? $request->ip())));
        \Illuminate\Support\Facades\RateLimiter::for('identity', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('id:'.($request->user()?->id ?? $request->ip())));
        \Illuminate\Support\Facades\RateLimiter::for('sso', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('sso:'.$request->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('exam-start', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by('s:'.($request->user()?->id ?? $request->ip())));
    }

    /** Failed sign-ins and lockouts are recorded for security monitoring (Target 7, 216, 218). */
    protected function securityLogging(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class, function ($e) {
            \App\Models\SecurityEvent::create(['tenant_id' => tenant_id(), 'user_id' => $e->user?->id, 'type' => 'login_failed',
                'ip' => request()->ip(), 'path' => 'login', 'data' => ['email' => mb_substr((string) ($e->credentials['email'] ?? ''), 0, 190)], 'created_at' => now()]);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Lockout::class, function ($e) {
            \App\Models\SecurityEvent::create(['tenant_id' => tenant_id(), 'type' => 'login_locked', 'ip' => $e->request->ip(), 'path' => 'login',
                'data' => ['email' => mb_substr((string) $e->request->input('email'), 0, 190)], 'created_at' => now()]);
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($e) {
            \App\Models\SecurityEvent::create(['tenant_id' => tenant_id(), 'user_id' => $e->user->id, 'type' => 'login', 'ip' => request()->ip(),
                'path' => 'login', 'data' => ['ua' => mb_substr((string) request()->userAgent(), 0, 190)], 'created_at' => now()]);
            // when this session began: a later "sign out everywhere" refuses it (EnsureSessionCurrent)
            if (request()->hasSession()) {
                request()->session()->put('auth_at', microtime(true));
            }
            // students: one signed-in computer at a time
            \App\Http\Middleware\SingleStudentSession::claim($e->user, request());
            \App\Support\Exams\ExamExaminers::claim($e->user, request());
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, function ($e) {
            if ($e->user) {
                \App\Models\SecurityEvent::create(['tenant_id' => tenant_id(), 'user_id' => $e->user->id, 'type' => 'logout', 'ip' => request()->ip(), 'path' => 'logout', 'created_at' => now()]);
                \App\Support\Exams\ExamExaminers::release($e->user, request());
            }
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\PasswordReset::class, function ($e) {
            app(\App\Services\Audit\AuditLogger::class)->record('security.password_reset', $e->user, [], $e->user->id);
            app(\App\Services\Security\SessionTerminator::class)->endFor($e->user, 'password reset');
        });
    }
}
