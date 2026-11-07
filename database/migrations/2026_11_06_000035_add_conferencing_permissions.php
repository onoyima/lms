<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Adds the two conferencing permissions and gives them to each institution's administrator role. Inserts only. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['meetings.host', 'conferencing.manage'] as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            $pid = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            foreach (DB::table('roles')->where('name', 'institution_admin')->pluck('id') as $rid) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $pid, 'role_id' => $rid]);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // permissions are left in place: roles may have been given them since
    }
};
