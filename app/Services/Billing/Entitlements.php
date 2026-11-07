<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Services\Modules\ModuleRegistry;
use App\Support\Platform\PlatformSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The one place that knows what an institution's subscription allows and how much of it is used.
 *
 *   effective limit = package limit, replaced by the subscription's own (enterprise) limit when set,
 *                     plus any extra capacity bought (active add-ons); null = unlimited.
 *
 * Every limit is enforced on the server (model events, upload middleware, API key middleware, exam start),
 * never by hiding buttons. Values live in the database and are changed from the platform console.
 */
class Entitlements
{
    /** key => [label, unit, what is counted] — the kinds of limit; their values are data, not code. */
    public const LIMITS = [
        'students' => ['Students', 'accounts', 'Active student accounts (applicants included)'],
        'staff' => ['Staff', 'accounts', 'Active staff accounts'],
        'admins' => ['Administrators', 'people', 'People holding a role with institution-wide power (settings, people, roles, integrations, billing)'],
        'faculties' => ['Faculties', '', 'Faculties in the academic structure'],
        'departments' => ['Departments', '', 'Departments in the academic structure'],
        'campuses' => ['Campuses', '', 'Different campuses used by halls and venues'],
        'storage_mb' => ['Storage', 'MB', 'Course materials, submissions, recordings and exam evidence'],
        'upload_mb' => ['Largest upload', 'MB', 'Size of a single uploaded file'],
        'api_requests_monthly' => ['API requests', 'per month', 'Calls made with the institution\'s API keys this month'],
        'attempts_monthly' => ['Exam and quiz attempts', 'per month', 'Attempts started this month'],
        'modules' => ['Optional modules', '', 'Modules switched on beyond the always-on core'],
    ];

    /** Limits that are a ceiling per item rather than a total used up. */
    public const CEILINGS = ['upload_mb'];

    public function __construct(private TenantContext $context, private ModuleRegistry $modules) {}

    private function tenant(?Tenant $tenant): ?Tenant
    {
        return $tenant ?? $this->context->get();
    }

    public static function current(?int $tenantId): ?Subscription
    {
        return $tenantId ? Subscription::where('tenant_id', $tenantId)->orderByDesc('id')->first() : null;
    }

    /** @return array<string, int|null> */
    public function limits(?Tenant $tenant = null): array
    {
        $tenant = $this->tenant($tenant);
        if (! $tenant) {
            return array_fill_keys(array_keys(self::LIMITS), null);
        }

        return Cache::remember("entitlements:{$tenant->id}:limits", 60, function () use ($tenant) {
            $planLimits = (array) ($tenant->plan?->limits ?? []);
            $sub = self::current($tenant->id);
            $subLimits = (array) ($sub?->limits ?? []);
            $addons = TenantAddon::current()->where('tenant_id', $tenant->id)->selectRaw('limit_key, SUM(quantity) as q')->groupBy('limit_key')->pluck('q', 'limit_key');
            $out = [];
            foreach (array_keys(self::LIMITS) as $key) {
                $base = array_key_exists($key, $subLimits) ? $subLimits[$key] : ($planLimits[$key] ?? null);
                if ($key === 'upload_mb' && $base === null) {
                    $base = (int) PlatformSettings::get('default_upload_mb');
                }
                $out[$key] = $base === null || $base === '' ? null : (int) $base + (int) ($addons[$key] ?? 0);
            }

            return $out;
        });
    }

    public function limit(string $key, ?Tenant $tenant = null): ?int
    {
        return $this->limits($tenant)[$key] ?? null;
    }

    public static function forget(int $tenantId): void
    {
        Cache::forget("entitlements:$tenantId:limits");
    }

    /** How much of a limit is in use now (fresh from the database: used for enforcement). */
    public function usage(string $key, ?Tenant $tenant = null): int|float
    {
        $tenant = $this->tenant($tenant);
        if (! $tenant) {
            return 0;
        }
        $tid = $tenant->id;

        return match ($key) {
            'students' => DB::table('users')->where('tenant_id', $tid)->where('user_type', 'student')->where('status', 'active')->count(),
            'staff' => DB::table('users')->where('tenant_id', $tid)->where('user_type', 'staff')->where('status', 'active')->count(),
            'admins' => count($this->adminIds($tid)),
            'faculties' => DB::table('faculties')->where('tenant_id', $tid)->count(),
            'departments' => DB::table('departments')->where('tenant_id', $tid)->count(),
            'campuses' => DB::table('venues')->where('tenant_id', $tid)->whereNotNull('campus')->where('campus', '!=', '')->distinct()->count('campus'),
            'storage_mb' => round($this->storageBytes($tid) / 1048576, 1),
            'upload_mb' => 0,
            'api_requests_monthly' => (int) Cache::get(self::apiKey($tid), 0),
            'attempts_monthly' => DB::table('attempts')->join('users', 'users.id', '=', 'attempts.user_id')->where('attempts.tenant_id', $tid)
                ->where('users.user_type', '!=', 'sandbox')->where('attempts.created_at', '>=', now()->startOfMonth())->count(),
            'modules' => count($this->countedModules($tenant)),
            default => 0,
        };
    }

    /** @return array<string, array{label: string, unit: string, about: string, limit: ?int, used: int|float, percent: ?int}> */
    public function report(?Tenant $tenant = null): array
    {
        $limits = $this->limits($tenant);
        $out = [];
        foreach (self::LIMITS as $key => [$label, $unit, $about]) {
            $used = in_array($key, self::CEILINGS, true) ? 0 : $this->usage($key, $tenant);
            $limit = $limits[$key];
            $out[$key] = ['label' => $label, 'unit' => $unit, 'about' => $about, 'limit' => $limit, 'used' => $used, 'ceiling' => in_array($key, self::CEILINGS, true),
                'percent' => $limit && ! in_array($key, self::CEILINGS, true) ? (int) min(100, round($used / $limit * 100)) : null];
        }

        return $out;
    }

    /** Is there room to add $amount more? */
    public function allows(string $key, int|float $amount = 1, ?Tenant $tenant = null): bool
    {
        $tenant = $this->tenant($tenant);
        $limit = $tenant ? $this->limit($key, $tenant) : null;
        if ($limit === null) {
            return true;
        }

        return in_array($key, self::CEILINGS, true) ? $amount <= $limit : $this->usage($key, $tenant) + $amount <= $limit;
    }

    /** Refuse, with a plain explanation, when the subscription has no room left. */
    public function check(string $key, int|float $amount = 1, ?Tenant $tenant = null): void
    {
        $tenant = $this->tenant($tenant);
        if ($tenant && ! $this->allows($key, $amount, $tenant)) {
            throw new CapacityExceeded($key, (int) $this->limit($key, $tenant), in_array($key, self::CEILINGS, true) ? $amount : $this->usage($key, $tenant));
        }
    }

    /**
     * Optional modules that use the subscription's module allowance: switched on, not always-on core, and not
     * only there through a temporary grant (time-limited access the platform team gives does not use a slot).
     *
     * @return list<string>
     */
    public function countedModules(Tenant $tenant): array
    {
        $tenant->loadMissing('moduleOverrides');
        $temporary = $tenant->moduleOverrides->filter(fn ($o) => $o->enabled && $o->expires_at && $o->current())->pluck('module')->all();
        $planModules = (array) ($tenant->plan?->modules ?? []);

        return array_values(array_filter($this->modules->enabledFor($tenant), fn ($m) => ! ($this->modules->all()[$m]['always_on'] ?? false)
            && ! (in_array($m, $temporary, true) && ! in_array($m, $planModules, true))));
    }

    /** After a module change: refuse it when more optional modules are on than the subscription allows. */
    public function assertModuleAllowance(Tenant $tenant): void
    {
        $tenant->unsetRelation('moduleOverrides');
        $limit = $this->limit('modules', $tenant);
        $used = count($this->countedModules($tenant));
        if ($limit !== null && $used > $limit) {
            throw new CapacityExceeded('modules', $limit, $used);
        }
    }

    /** @return list<int> active users holding a role with institution-wide power */
    public function adminIds(int $tid): array
    {
        return DB::table('model_has_roles as mr')->join('role_has_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')->join('users as u', 'u.id', '=', 'mr.model_id')
            ->where('mr.tenant_id', $tid)->where('mr.model_type', \App\Models\User::class)->whereIn('p.name', config('roles.privileged'))
            ->where('u.status', 'active')->distinct()->pluck('mr.model_id')->map(fn ($i) => (int) $i)->all();
    }

    public function storageBytes(int $tid): int
    {
        return (int) DB::table('course_items')->where('tenant_id', $tid)->sum('file_size')
            + (int) DB::table('assignment_submissions')->where('tenant_id', $tid)->sum('file_size')
            + (int) DB::table('media_recordings')->where('tenant_id', $tid)->sum('size')
            + (int) DB::table('evidence_files')->where('tenant_id', $tid)->sum('size');
    }

    public static function apiKey(int $tid): string
    {
        return "entitlements:$tid:api:".now()->format('Ym');
    }

    /** Count one API call; refuse it when the month's allowance is used up. */
    public function countApiCall(Tenant $tenant): void
    {
        $limit = $this->limit('api_requests_monthly', $tenant);
        $key = self::apiKey($tenant->id);
        Cache::add($key, 0, now()->endOfMonth()->addDay());
        $n = Cache::increment($key);
        if ($limit !== null && $n > $limit) {
            throw new CapacityExceeded('api_requests_monthly', $limit, $n - 1);
        }
    }
}
