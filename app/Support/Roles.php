<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * The current institution's roles, read from its own database rows — never from a fixed list —
 * so roles an institution creates, renames or changes are used everywhere at once.
 */
class Roles
{
    /** @return Collection<int, Role> */
    public static function all(): Collection
    {
        return Role::where('tenant_id', tenant_id())->with('permissions:id,name')->orderByDesc('is_system')->orderBy('name')->get();
    }

    /** @return list<string> */
    public static function names(): array
    {
        return Role::where('tenant_id', tenant_id())->orderBy('name')->pluck('name')->all();
    }

    /** name => label, for showing roles in plain words */
    public static function labels(): array
    {
        return Role::where('tenant_id', tenant_id())->get(['name', 'label'])->mapWithKeys(fn ($r) => [$r->name => $r->label ?: ucfirst(str_replace('_', ' ', $r->name))])->all();
    }

    /** @return list<string> */
    public static function permissionsOf(string $role): array
    {
        return Role::where('tenant_id', tenant_id())->where('name', $role)->first()?->permissions->pluck('name')->all() ?? [];
    }

    /** A role carrying institution-wide power (settings, people, roles, integrations, billing). */
    public static function privileged(string $role): bool
    {
        return (bool) array_intersect(self::permissionsOf($role), config('roles.privileged'));
    }

    /** Nobody can hand out a role holding a permission they do not hold themselves. */
    public static function canGrant(User $actor, string $role): bool
    {
        return collect(self::permissionsOf($role))->every(fn ($p) => $actor->can($p));
    }

    /**
     * Someone placed on a course must be able to teach it. If they cannot yet, give them the institution's
     * teaching role: the one called "lecturer" if it still exists, otherwise the smallest role that can teach.
     */
    public static function ensureTeacher(User $user): void
    {
        if ($user->can('lms.teach')) {
            return;
        }
        $role = Role::where('tenant_id', tenant_id())->where('name', 'lecturer')->first()
            ?? Role::where('tenant_id', tenant_id())->whereHas('permissions', fn ($q) => $q->where('name', 'lms.teach'))->withCount('permissions')->orderBy('permissions_count')->get()
                ->first(fn ($r) => ! self::privileged($r->name));
        if ($role && $role->permissions()->where('name', 'lms.teach')->exists()) {
            $user->assignRole($role);
        }
    }

    /** Someone studying must be able to: the institution's "student" role, otherwise the smallest role that can study. */
    public static function ensureLearner(User $user): void
    {
        if ($user->can('lms.learn')) {
            return;
        }
        $role = Role::where('tenant_id', tenant_id())->where('name', 'student')->first()
            ?? Role::where('tenant_id', tenant_id())->whereHas('permissions', fn ($q) => $q->where('name', 'lms.learn'))->withCount('permissions')->orderBy('permissions_count')->get()
                ->first(fn ($r) => ! self::privileged($r->name));
        if ($role && $role->permissions()->where('name', 'lms.learn')->exists()) {
            $user->assignRole($role);
        }
    }

    /** A role by name, created (without permissions) if the institution does not have it yet. */
    public static function ensureRole(string $name, string $label, ?string $description = null): Role
    {
        $role = Role::where('tenant_id', tenant_id())->where('name', $name)->first();
        if (! $role) {
            $role = Role::create(['name' => $name, 'guard_name' => 'web', 'tenant_id' => tenant_id()]);
            $role->forceFill(['label' => $label, 'description' => $description, 'is_system' => false])->save();
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $role;
    }
}
