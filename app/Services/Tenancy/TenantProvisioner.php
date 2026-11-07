<?php

namespace App\Services\Tenancy;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Creates an institution with its default roles and first administrator. */
class TenantProvisioner
{
    public function __construct(private TenantContext $context) {}

    public static function syncPermissions(): void
    {
        foreach (config('roles.permissions') as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    public function provision(array $attributes, string $planKey, ?array $admin = null): Tenant
    {
        self::syncPermissions();

        $tenant = Tenant::firstOrCreate(
            ['slug' => $attributes['slug']],
            $attributes + ['plan_id' => Plan::where('key', $planKey)->value('id')]
        );

        $this->context->run($tenant, function () use ($tenant, $admin) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            // starting roles; an institution's later changes to them are kept (only new roles get defaults)
            foreach (config('roles.roles') as $roleName => $perms) {
                $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
                $label = config("roles.role_labels.$roleName", []);
                $role->forceFill(['is_system' => true, 'label' => $role->label ?? ($label[0] ?? null), 'description' => $role->description ?? ($label[1] ?? null)])->save();
                if ($role->wasRecentlyCreated || $perms === ['*']) {
                    $role->syncPermissions($perms === ['*'] ? config('roles.permissions') : $perms); // the administrator always holds everything
                }
            }

            if ($admin) {
                $user = User::firstOrCreate(
                    ['email' => $admin['email'], 'tenant_id' => $tenant->id],
                    ['name' => $admin['name'], 'password' => $admin['password'], 'user_type' => 'staff']
                );
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->assignRole('institution_admin');
            }
        });

        app(\App\Services\Lms\StandardArticles::class)->ensure($tenant);

        return $tenant;
    }
}
