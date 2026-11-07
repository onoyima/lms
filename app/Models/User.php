<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'identifier', 'user_type', 'status'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;
    use HasRoles {
        assignRole as private grantRoles;
        syncRoles as private replaceRoles;
        removeRole as private dropRole;
    }

    /**
     * Users are visible only inside their own institution; with no institution in context
     * (the platform console) only platform administrators are. Platform administrators never
     * appear in an institution's lists. Signing in uses forSignIn(), which also finds a
     * platform administrator so they can sign in at any institution's address.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantId = app(TenantContext::class)->id();
            $tenantId === null
                ? $query->whereNull('users.tenant_id')->where('users.is_platform_admin', true)
                : $query->where('users.tenant_id', $tenantId);
        });

        static::creating(function (User $user) {
            if ($user->tenant_id === null && ! $user->is_platform_admin) {
                $user->tenant_id = app(TenantContext::class)->id();
            }
        });

        // An account switched off is signed out everywhere at once
        static::updated(function (User $user) {
            if ($user->wasChanged('status') && ($user->getOriginal('status') ?? 'active') === 'active' && $user->status !== 'active') {
                app(\App\Services\Security\SessionTerminator::class)->endFor($user, 'account '.$user->status);
            }
        });

        // Subscription capacity: a new or re-activated student / staff account needs a free seat (every path:
        // forms, imports, the API, portal sync and sign-in). Accounts going inactive never need one.
        static::saving(function (User $user) {
            $tenantId = $user->tenant_id ?? app(TenantContext::class)->id(); // set on 'creating', after 'saving'
            if (! $tenantId || $user->is_platform_admin || ! in_array($user->user_type, ['student', 'staff'], true) || ($user->status ?? 'active') !== 'active') {
                return;
            }
            $wasCounted = $user->exists && $user->getOriginal('status') === 'active' && $user->getOriginal('user_type') === $user->user_type;
            if (! $wasCounted) {
                $ent = app(\App\Services\Billing\Entitlements::class);
                $key = $user->user_type === 'student' ? 'students' : 'staff';
                $tenant = Tenant::cached($tenantId);
                if ($ent->limit($key, $tenant) === null) {
                    return; // unlimited: nothing to count
                }
                // one seat check at a time per institution, held until the row is written, so parallel
                // sign-ups or imports cannot both take the last seat
                $lock = \Illuminate\Support\Facades\Cache::lock("seat:$tenantId:$key", 15);
                $lock->block(10);
                try {
                    $ent->check($key, 1, $tenant);
                } catch (\Throwable $e) {
                    $lock->release();
                    throw $e;
                }
                self::$seatLocks[spl_object_id($user)] = $lock;
            }
        });
        $release = function (User $user) {
            if ($lock = self::$seatLocks[spl_object_id($user)] ?? null) {
                $lock->release();
                unset(self::$seatLocks[spl_object_id($user)]);
            }
        };
        static::saved($release);
    }

    /** @var array<int, \Illuminate\Contracts\Cache\Lock> seat locks held between 'saving' and 'saved' */
    private static array $seatLocks = [];

    /** Giving someone institution-wide power uses an administrator seat. */
    private function guardAdminSeat(array $roles): void
    {
        if (! $this->tenant_id || $this->is_platform_admin) {
            return;
        }
        $names = collect($roles)->flatten()->map(fn ($r) => $r instanceof \Spatie\Permission\Models\Role ? $r->name : (string) $r)->filter()->all();
        if (! array_filter($names, fn ($n) => \App\Support\Roles::privileged($n))) {
            return;
        }
        $ent = app(\App\Services\Billing\Entitlements::class);
        if (! in_array($this->id, $ent->adminIds($this->tenant_id), true)) {
            $ent->check('admins', 1, Tenant::cached($this->tenant_id));
        }
    }

    public function assignRole(...$roles)
    {
        $this->guardAdminSeat($roles);

        return $this->grantRoles(...$roles);
    }

    public function syncRoles(...$roles)
    {
        $this->guardAdminSeat($roles);
        $before = \App\Services\Security\SessionTerminator::privileged($this);
        $out = $this->replaceRoles(...$roles);
        app(\App\Services\Security\SessionTerminator::class)->afterChange($this, $before, 'roles changed');

        return $out;
    }

    /** Losing institution-wide power signs the person out everywhere at once. */
    public function removeRole($role)
    {
        $before = \App\Services\Security\SessionTerminator::privileged($this);
        $out = $this->dropRole($role);
        app(\App\Services\Security\SessionTerminator::class)->afterChange($this, $before, 'role removed');

        return $out;
    }

    /** Accounts that may sign in here: this institution's people, and platform administrators. */
    public static function forSignIn(): Builder
    {
        $tenantId = app(TenantContext::class)->id();

        return static::withoutGlobalScope('tenant')->where(function (Builder $q) use ($tenantId) {
            $q->where(fn (Builder $p) => $p->whereNull('users.tenant_id')->where('users.is_platform_admin', true));
            if ($tenantId !== null) {
                $q->orWhere('users.tenant_id', $tenantId);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'preferences' => 'array',
            'notification_preferences' => 'array',
        ];
    }

    public function studentProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }
}
