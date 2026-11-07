<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam scheduling: resits and supplementaries, generated timetables, proctor availability,
 * proctor training, disconnection alerts and per-user console preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('kind', 16)->default('regular')->after('category'); // regular, resit, supplementary
            $table->foreignId('parent_exam_id')->nullable()->after('kind')->constrained('exams')->nullOnDelete();
        });

        Schema::table('proctor_assignments', function (Blueprint $table) {
            $table->timestamp('offline_alerted_at')->nullable()->after('last_seen_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable(); // e.g. proctor camera layout
        });

        Schema::create('timetable_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('status', 12)->default('draft'); // draft, committed, discarded
            $table->json('params');
            $table->json('proposal');
            $table->json('unplaced')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('proctor_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('from_time');
            $table->time('to_time');
            $table->boolean('available')->default(true); // false = blocked out
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'date']);
            $table->index(['user_id', 'date']);
        });

        Schema::create('proctor_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_session_id')->nullable()->constrained()->nullOnDelete();
            $table->json('tasks')->nullable();          // which practice tasks were done
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'exam_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proctor_trainings');
        Schema::dropIfExists('proctor_availability');
        Schema::dropIfExists('timetable_runs');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('preferences'));
        Schema::table('proctor_assignments', fn (Blueprint $t) => $t->dropColumn('offline_alerted_at'));
        Schema::table('exams', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_exam_id');
            $t->dropColumn('kind');
        });
    }
};
