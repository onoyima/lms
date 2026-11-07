<?php

namespace App\Services\Security;

use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\Policy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Security self-check (exams office / ICT procedure): the settings and habits that keep the
 * platform safe, checked in one go before each exam season and after every upgrade. It reads
 * configuration and counts only; it changes nothing. Run: php artisan security:check
 */
class SecuritySelfCheck
{
    public function __construct(private AuditLogger $audit) {}

    /** @return list<array{area: string, check: string, status: 'pass'|'warn'|'fail', detail: string}> */
    public function run(): array
    {
        $r = [];
        $add = function (string $area, string $check, bool|string $ok, string $detail = '') use (&$r) {
            $r[] = ['area' => $area, 'check' => $check, 'status' => $ok === true ? 'pass' : ($ok === false ? 'fail' : $ok), 'detail' => $detail];
        };
        $prod = app()->environment('production');

        // application
        $add('Application', 'Debug mode is off', ! config('app.debug') ?: ($prod ? false : 'warn'), config('app.debug') ? 'APP_DEBUG=true shows code and settings in error pages.' : '');
        $add('Application', 'Encryption key is set', strlen((string) config('app.key')) >= 32, 'APP_KEY protects sessions, secrets and evidence.');
        $add('Application', 'Site address uses https', str_starts_with((string) config('app.url'), 'https://') ?: ($prod ? false : 'warn'), (string) config('app.url'));
        $add('Application', 'Session cookie is secure, HTTP-only and same-site', (config('session.secure') || ! $prod) && config('session.http_only') && in_array(config('session.same_site'), ['lax', 'strict'], true) ?: 'warn',
            'SESSION_SECURE_COOKIE=true in production.');
        $add('Application', 'Sessions are encrypted or server-side', config('session.driver') !== 'cookie' || config('session.encrypt'), 'driver: '.config('session.driver'));
        $env = base_path('.env');
        $add('Application', '.env file is not readable by other users', ! is_file($env) || DIRECTORY_SEPARATOR === '\\' || (fileperms($env) & 0o004) === 0 ?: 'warn', 'chmod 640 .env');
        $add('Application', 'Two-factor sign-in is enforced', (bool) config('platform.enforce_mfa'), 'ENFORCE_MFA=true');

        // accounts
        $platformNo2fa = DB::table('users')->whereNull('tenant_id')->where('is_platform_admin', true)->where('status', 'active')->whereNull('two_factor_confirmed_at')->count();
        $add('Accounts', 'Every platform administrator uses two-factor sign-in', $platformNo2fa === 0, $platformNo2fa ? "$platformNo2fa without it" : '');
        $adminsNo2fa = DB::table('users')->join('model_has_roles as mr', fn ($j) => $j->on('mr.model_id', '=', 'users.id')->where('mr.model_type', \App\Models\User::class))
            ->join('roles', 'roles.id', '=', 'mr.role_id')->whereIn('roles.name', ['institution_admin', 'ict_admin', 'exam_officer'])
            ->where('users.status', 'active')->whereNull('users.two_factor_confirmed_at')->distinct()->count('users.id');
        $add('Accounts', 'Institution administrators and exams officers use two-factor sign-in', $adminsNo2fa === 0 ?: 'warn', $adminsNo2fa ? "$adminsNo2fa without it (they are asked at next sign-in)" : '');
        $locked = DB::table('security_events')->where('type', 'login_failed')->where('created_at', '>', now()->subDay())->count();
        $add('Accounts', 'Failed sign-ins in the last 24 hours', $locked < 200 ?: 'warn', "$locked failed sign-ins");

        // integrations
        $openKeys = DB::table('api_keys')->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        $noIp = (clone $openKeys)->whereNull('allowed_ips')->count();
        $stale = (clone $openKeys)->where(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', now()->subDays(60))->orWhere('last_used_at', '<', now()->subDays(90)))->count();
        $add('Integrations', 'API keys are limited to known addresses', $noIp === 0 ?: 'warn', $noIp ? "$noIp key(s) usable from any address" : '');
        $add('Integrations', 'No unused API keys', $stale === 0 ?: 'warn', $stale ? "$stale key(s) unused for 60–90 days; revoke them" : '');
        $add('Integrations', 'Payment webhooks are verifiable', ! config('platform.paystack.public') || (bool) config('platform.paystack.secret'), 'PAYSTACK_SECRET_KEY signs payment notices.');
        $lk = (string) config('platform.livekit.secret');
        $add('Integrations', 'Media server secret is strong', ! config('platform.livekit.url') || strlen($lk) >= 32, 'LIVEKIT_API_SECRET should be 32+ random characters.');

        // data protection
        $last = DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->max('created_at');
        $add('Data', 'A backup finished in the last 26 hours', $last && now()->diffInHours($last, true) <= 26, $last ? "last: $last" : 'no successful backup recorded');
        $add('Data', 'Backups are encrypted', (bool) config('platform.backup.passphrase') ?: 'warn', 'BACKUP_PASSPHRASE');
        $restore = DB::table('backup_runs')->where('kind', 'restore_test')->where('status', 'ok')->max('created_at');
        $add('Data', 'A restore test passed in the last 8 days', $restore && now()->diffInDays($restore, true) <= 8 ?: 'warn', $restore ? "last: $restore" : 'none recorded');
        $add('Data', 'Database files are encrypted at rest', $this->dbEncrypted(), 'deploy/mariadb/encryption.cnf, then php artisan db:encrypt-tables');
        $add('Data', 'Private files are not in the public folder', ! is_dir(public_path('storage/private')) && ! is_link(public_path('storage/app')), 'storage/app/private must never be linked into public/.');

        // operations
        $beat = Cache::get('health:scheduler');
        $add('Operations', 'Scheduler is running', $beat && now()->getTimestamp() - (int) $beat < 300, 'Needed for auto-submit, backups, evidence deletion and billing.');
        $add('Operations', 'Queue is not stuck', DB::table('jobs')->where('created_at', '<', now()->subMinutes(15)->getTimestamp())->count() === 0 ?: 'warn', 'jobs waiting more than 15 minutes');
        $failed = DB::table('failed_jobs')->where('failed_at', '>', now()->subDay())->count();
        $add('Operations', 'No failed background jobs today', $failed === 0 ?: 'warn', $failed ? "$failed failed" : '');

        // per institution
        foreach (Tenant::where('status', 'active')->get() as $t) {
            app(TenantContext::class)->run($t, function () use ($t, $add) {
                $broken = $this->audit->verify($t->id);
                $add($t->name, 'Audit trail is intact', $broken === null, $broken ? "first altered entry: #$broken" : '');
                $add($t->name, 'Password policy is at least 12 characters', (int) Policy::get('password_min') >= 12 ?: 'warn', 'Policy > Passwords');
                $add($t->name, 'Exam-day contacts are set', count((array) Policy::get('incident_contacts')) > 0 ?: 'warn', 'Policy > Exam-day contacts');
                $review = DB::table('access_reviews')->where('tenant_id', $t->id)->max('completed_at');
                $add($t->name, 'Access review is up to date', $review && now()->diffInDays($review, true) <= (int) Policy::get('access_review_days') ?: 'warn', $review ? "last: $review" : 'never done');
            });
        }

        $add('Operations', 'Recovery drill passed in the last 35 days', DB::table('dr_drills')->where('status', 'passed')->where('started_at', '>', now()->subDays(35))->exists() ?: 'warn', 'Platform > System health > Run a drill');
        $alerts = DB::table('system_alerts')->whereNull('resolved_at')->where('level', 'critical')->count();
        $add('Operations', 'No critical alerts open', $alerts === 0, $alerts ? "$alerts open" : '');

        Cache::put('security:check', ['at' => now()->toIso8601String(), 'results' => $r], now()->addDays(30));

        return $r;
    }

    /** MariaDB encrypts table files with a key the database copy does not contain. */
    private function dbEncrypted(): bool|string
    {
        try {
            if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                return 'warn';
            }
            $on = DB::selectOne("SHOW GLOBAL VARIABLES LIKE 'innodb_encrypt_tables'");
            if (! $on || ! in_array(strtoupper((string) $on->Value), ['ON', 'FORCE'], true)) {
                return app()->environment('production') ? false : 'warn';
            }
            $plain = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_TYPE', 'BASE TABLE')
                ->where(fn ($q) => $q->whereNull('CREATE_OPTIONS')->orWhere('CREATE_OPTIONS', 'not like', '%ENCRYPTED=YES%'))->count();

            return $plain === 0 || strtoupper((string) $on->Value) === 'FORCE' ? true : 'warn';
        } catch (\Throwable) {
            return 'warn';
        }
    }
}
