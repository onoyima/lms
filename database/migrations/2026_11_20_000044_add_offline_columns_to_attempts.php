<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline-safe exams: time given back when the candidate's computer went off (power cut, crash,
 * battery), and the end of the last such gap so a retried report is never counted twice.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $t) {
            if (! Schema::hasColumn('attempts', 'device_off_seconds')) {
                $t->unsignedInteger('device_off_seconds')->default(0)->after('paused_seconds');
            }
            if (! Schema::hasColumn('attempts', 'device_off_until')) {
                $t->dateTime('device_off_until', 3)->nullable()->after('device_off_seconds');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attempts', function (Blueprint $t) {
            $t->dropColumn(['device_off_seconds', 'device_off_until']);
        });
    }
};
