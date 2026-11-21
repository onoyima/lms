<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A result change can be the correction of one answer in a script (the old system's answer override). Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('result_changes', 'answer')) {
            Schema::table('result_changes', function (Blueprint $t) {
                $t->json('answer')->nullable()->after('reason'); // {attempt_id, question_id, number, from, to}
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('result_changes', 'answer')) {
            Schema::table('result_changes', fn (Blueprint $t) => $t->dropColumn('answer'));
        }
    }
};
