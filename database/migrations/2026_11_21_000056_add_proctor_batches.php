<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live proctoring in batches: a sitting is split into batches of N candidates (20, 30, 50…), each
 * batch has its own live-video room, and supervisors can be given particular batches.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('exam_sessions', 'proctor_batch_size')) {
            Schema::table('exam_sessions', fn (Blueprint $t) => $t->unsignedSmallInteger('proctor_batch_size')->nullable());
        }
        if (! Schema::hasColumn('exam_candidates', 'proctor_batch')) {
            Schema::table('exam_candidates', function (Blueprint $t) {
                $t->unsignedSmallInteger('proctor_batch')->nullable();
                $t->index(['exam_session_id', 'proctor_batch']);
            });
        }
        if (! Schema::hasColumn('proctor_assignments', 'batches')) {
            Schema::table('proctor_assignments', fn (Blueprint $t) => $t->json('batches')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('proctor_assignments', 'batches')) {
            Schema::table('proctor_assignments', fn (Blueprint $t) => $t->dropColumn('batches'));
        }
        if (Schema::hasColumn('exam_candidates', 'proctor_batch')) {
            Schema::table('exam_candidates', function (Blueprint $t) {
                $t->dropIndex(['exam_session_id', 'proctor_batch']);
                $t->dropColumn('proctor_batch');
            });
        }
        if (Schema::hasColumn('exam_sessions', 'proctor_batch_size')) {
            Schema::table('exam_sessions', fn (Blueprint $t) => $t->dropColumn('proctor_batch_size'));
        }
    }
};
