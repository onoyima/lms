<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teaching assignments that come from the institution's directory are marked, so the directory can take
 * them away again when the portal does, without touching the ones set by hand on the platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_offering_staff', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('role'); // null = set on the platform; "directory" = from the portal
        });
    }

    public function down(): void
    {
        Schema::table('course_offering_staff', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
