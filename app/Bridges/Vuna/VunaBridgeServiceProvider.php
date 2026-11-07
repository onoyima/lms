<?php

namespace App\Bridges\Vuna;

use App\Bridges\Vuna\Http\DirectoryController;
use App\Bridges\Vuna\Http\OidcController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires up the portal bridge: its own read-only database connection (this computer only), its routes
 * under /bridge/vuna and its sign-in pages. Nothing here touches the platform's own institutions or users.
 */
class VunaBridgeServiceProvider extends ServiceProvider
{
    public const PREFIX = 'bridge/vuna';

    public function register(): void
    {
        $this->app->singleton(Portal::class);
        $this->app->singleton(Oidc\Keys::class);
        $this->registerConnection();
    }

    /** True only for a database on this computer: sqlite, a unix socket, or localhost / 127.0.0.1 / ::1. */
    public static function isLocal(array $db): bool
    {
        $driver = (string) ($db['driver'] ?? '');
        if ($driver === 'sqlite') {
            return true;
        }
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }
        if (trim((string) ($db['unix_socket'] ?? '')) !== '') {
            return true;
        }

        return in_array(strtolower(trim((string) ($db['host'] ?? ''))), ['localhost', '127.0.0.1', '::1'], true);
    }

    public function registerConnection(): void
    {
        $db = (array) config('vuna_bridge.db');
        if (! self::isLocal($db) && ! config('vuna_bridge.allow_remote')) {
            // a database on another computer only when the institution has switched that on
            config(['database.connections.'.Portal::CONNECTION => null, 'vuna_bridge.blocked' => 'The portal database must be on this computer (localhost), or set VUNA_DB_ALLOW_REMOTE=true to read a remote one.']);

            return;
        }
        if (in_array($db['driver'], ['mysql', 'mariadb'], true)) {
            $attr = fn (string $name) => defined("Pdo\\Mysql::$name") ? constant("Pdo\\Mysql::$name") : constant("PDO::MYSQL_$name");
            // the bridge only reads: the database session itself refuses writes
            $db['options'] = [$attr('ATTR_INIT_COMMAND') => 'SET SESSION TRANSACTION READ ONLY', \PDO::ATTR_TIMEOUT => 15];
            if (! empty($db['ssl_ca'])) {
                $db['options'][$attr('ATTR_SSL_CA')] = $db['ssl_ca'];
            }
        }
        unset($db['ssl_ca']);
        config(['database.connections.'.Portal::CONNECTION => $db, 'vuna_bridge.blocked' => null]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([Console\BridgeCheck::class, Console\DemoPortalCommand::class]);
        }
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::prefix(self::PREFIX)->middleware([Http\EnsureBridgeReady::class])->group(function () {
            // browser pages: session for "already signed in", CSRF on the form
            Route::middleware([
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            ])->group(function () {
                Route::get('authorize', [OidcController::class, 'authorize'])->middleware('throttle:60,1,vunab-auth')->name('vuna-bridge.authorize');
                Route::post('authorize', [OidcController::class, 'login'])->middleware('throttle:30,1,vunab-login')->name('vuna-bridge.login');
                Route::get('logout', [OidcController::class, 'logout'])->name('vuna-bridge.logout');
            });

            // machine endpoints: no cookies, no session
            Route::get('.well-known/openid-configuration', [OidcController::class, 'discovery'])->middleware('throttle:120,1,vunab-meta');
            Route::get('jwks', [OidcController::class, 'jwks'])->middleware('throttle:120,1,vunab-meta');
            Route::post('token', [OidcController::class, 'token'])->middleware('throttle:60,1,vunab-token');
            Route::match(['get', 'post'], 'userinfo', [OidcController::class, 'userinfo'])->middleware('throttle:120,1,vunab-ui');

            Route::prefix('api/v1')->middleware('throttle:600,1,vunab-api')->controller(DirectoryController::class)->group(function () {
                Route::get('term', 'term');
                Route::get('health', 'health');
                Route::get('faculties', 'faculties');
                Route::get('departments', 'departments');
                Route::get('programmes', 'programmes');
                Route::get('courses', 'courses');
                Route::get('students', 'students');
                Route::get('students/{id}', 'student')->whereNumber('id');
                Route::get('staff', 'staff');
                Route::get('staff/{id}', 'staffMember')->whereNumber('id');
                Route::get('applicants', 'applicants');
                Route::get('registrations', 'registrations');
                Route::get('teaching', 'teaching');
                Route::get('statuses', 'statuses');
                Route::post('authenticate', 'authenticate')->middleware('throttle:120,1,vunab-authn');
            });
        });
    }
}
