<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null only for platform staff (the SaaS operator), who are not inside any institution.
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->boolean('is_platform_admin')->default(false)->after('password');
            $table->string('identifier')->nullable()->after('email'); // matric number or staff number
            $table->string('user_type')->default('staff')->after('identifier'); // staff, student
            $table->string('status')->default('active')->after('user_type'); // active, suspended, withdrawn, graduated, deferred

            $table->dropUnique(['email']);
            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropUnique(['tenant_id', 'identifier']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['is_platform_admin', 'identifier', 'user_type', 'status']);
            $table->unique('email');
        });
    }
};
