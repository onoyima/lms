<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\RequireModule;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->preventRequestForgery(except: ['sso/*/acs', 'webhooks/paystack', 'webhooks/shop/paystack', 'webhooks/mail']); // verified by their signatures instead

        // The institution must be known before the signed-in user is loaded (users are
        // per institution) and before route-model binding, so records in URLs only
        // ever resolve inside the current institution.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ResolveTenant::class);

        $middleware->alias([
            'apikey' => \App\Http\Middleware\AuthenticateApiKey::class,
            'module' => RequireModule::class,
            'platform.admin' => RequirePlatformAdmin::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        ]);

        $middleware->api(prepend: [ResolveTenant::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\RecordRequestMetrics::class);

        $middleware->web(append: [
            ResolveTenant::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\RequireTwoFactor::class,
            \App\Http\Middleware\EnforcePlatformBoundary::class,
            \App\Http\Middleware\SuspendedInstitutionGate::class,
            \App\Http\Middleware\DemoGuard::class,
            \App\Http\Middleware\EnforceUploadCapacity::class,
            \App\Http\Middleware\PlatformMaintenance::class,
            \App\Http\Middleware\SupportSessionGuard::class,
            \App\Http\Middleware\EnsureSessionCurrent::class,
            \App\Http\Middleware\SingleStudentSession::class,
            \App\Http\Middleware\ExamExaminerScope::class,
            \App\Http\Middleware\ExamGuestScope::class,
            \App\Http\Middleware\TenantRateLimit::class,
        ]);
        $middleware->api(append: [\App\Http\Middleware\EnforceUploadCapacity::class, \App\Http\Middleware\PlatformMaintenance::class, \App\Http\Middleware\TenantRateLimit::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Refused/forbidden requests are recorded for security monitoring (Target 216-217)
        $exceptions->report(function (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if (in_array($e->getStatusCode(), [401, 403, 429], true)) {
                try {
                    \App\Models\SecurityEvent::create([
                        'tenant_id' => tenant_id(), 'user_id' => auth()->id(), 'type' => $e->getStatusCode() === 429 ? 'rate_limited' : 'forbidden',
                        'ip' => request()->ip(), 'path' => substr(request()->path(), 0, 255), 'data' => ['message' => substr($e->getMessage(), 0, 200)], 'created_at' => now(),
                    ]);
                } catch (\Throwable) {
                }
            }

            return false;
        });
        $exceptions->stopIgnoring(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        // A refused form action (409/422 with a reason) comes back to the same page as a message, not an error page
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($request->header('X-Inertia') && ! $request->isMethod('GET') && in_array($e->getStatusCode(), [409, 422], true) && $e->getMessage() !== '') {
                return back()->with('error', $e->getMessage());
            }
        });
        // Moving inside the app (Inertia) to a page that is refused or missing: the same app page with
        // the reason, Back and Home, instead of a bare error document in a pop-up
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            $status = $response->getStatusCode();
            if ($request->header('X-Inertia') && $request->isMethod('GET') && in_array($status, [403, 404, 500, 503], true) && ! app()->hasDebugModeEnabled()) {
                $message = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $status !== 500 ? $e->getMessage() : '';

                return \Inertia\Inertia::render('error', ['status' => $status, 'message' => $message])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
        // Subscription capacity reached: a clear message, never an error page
        $exceptions->dontReport(\App\Services\Billing\CapacityExceeded::class);
        $exceptions->render(function (\App\Services\Billing\CapacityExceeded $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage(), 'error' => 'capacity_exceeded', 'limit' => $e->key], 422);
            }

            return back()->with('error', $e->getMessage())->withErrors(['capacity' => $e->getMessage()]);
        });
        $exceptions->dontReportDuplicates();
    })->create();
