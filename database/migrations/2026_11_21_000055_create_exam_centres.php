<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam centres that run a sitting without internet: the main server sends a signed package, the
 * centre sends the scripts back. References keep both sides matched. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exam_centres')) {
            Schema::create('exam_centres', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('name', 160);
                $t->string('code', 40);
                $t->text('secret');                    // shared signing key, stored encrypted
                $t->string('role', 10)->default('centre'); // centre (on the main server) or main (on a centre's server)
                $t->text('notes')->nullable();
                $t->dateTime('last_package_at')->nullable();
                $t->dateTime('last_results_at')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'code']);
            });
        }
        foreach (['exam_sessions', 'attempts', 'questions', 'question_banks'] as $table) {
            if (! Schema::hasColumn($table, 'centre_ref')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('centre_ref', 80)->nullable()->index();
                });
            }
        }
        if (! Schema::hasColumn('exam_sessions', 'exam_centre_id')) {
            Schema::table('exam_sessions', function (Blueprint $t) {
                $t->foreignId('exam_centre_id')->nullable()->constrained('exam_centres')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('exam_sessions', 'exam_centre_id')) {
            Schema::table('exam_sessions', fn (Blueprint $t) => $t->dropConstrainedForeignId('exam_centre_id'));
        }
        foreach (['exam_sessions', 'attempts', 'questions', 'question_banks'] as $table) {
            if (Schema::hasColumn($table, 'centre_ref')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropIndex(['centre_ref']);
                    $t->dropColumn('centre_ref');
                });
            }
        }
        Schema::dropIfExists('exam_centres');
    }
};
