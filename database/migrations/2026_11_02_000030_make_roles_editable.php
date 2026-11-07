<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles become each institution's own: a name people understand, a description, whether it is one
 * of the starting roles, and the permissions the institution chooses. Single sign-on providers get
 * rules that give roles from the groups or attributes the institution's directory sends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label', 80)->nullable()->after('name');
            $table->string('description', 255)->nullable()->after('label');
            $table->boolean('is_system')->default(false)->after('description');
        });
        Schema::table('sso_providers', function (Blueprint $table) {
            $table->json('role_rules')->nullable()->after('default_role'); // [{"attribute":"groups","value":"Exams Office","role":"exam_officer"}]
            $table->string('type_attribute', 80)->nullable()->after('role_rules'); // e.g. eduPersonAffiliation: staff/student
        });

        // new permissions, given to the starting roles that hold them by default
        $now = now();
        foreach (['billing.manage', 'reports.all_departments', 'reports.faculty', 'exams.lead_hall'] as $perm) {
            DB::table('permissions')->updateOrInsert(['name' => $perm, 'guard_name' => 'web'], ['created_at' => $now, 'updated_at' => $now]);
        }
        $ids = DB::table('permissions')->pluck('id', 'name');
        foreach (config('roles.roles') as $role => $perms) {
            $label = config("roles.role_labels.$role");
            DB::table('roles')->where('name', $role)->update(['is_system' => true, 'label' => $label[0] ?? null, 'description' => $label[1] ?? null]);
            $perms = $perms === ['*'] ? array_keys($ids->all()) : $perms;
            foreach (DB::table('roles')->where('name', $role)->pluck('id') as $roleId) {
                foreach (array_intersect($perms, ['billing.manage', 'reports.all_departments', 'reports.faculty', 'exams.lead_hall']) as $p) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $ids[$p], 'role_id' => $roleId]);
                }
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('sso_providers', fn (Blueprint $t) => $t->dropColumn(['role_rules', 'type_attribute']));
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn(['label', 'description', 'is_system']));
    }
};
