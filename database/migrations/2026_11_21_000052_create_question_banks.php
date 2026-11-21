<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named question banks that belong to one exam, one exam category or one course (for example one
 * bank per topic). Questions keep working without a bank. Additive only: no data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('question_banks')) {
            Schema::create('question_banks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('name', 160);
                $t->string('topic', 190)->nullable();
                $t->text('description')->nullable();
                $t->foreignId('exam_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('exam_group_id')->nullable()->constrained('exam_groups')->nullOnDelete();
                $t->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['tenant_id', 'exam_id']);
                $t->index(['tenant_id', 'exam_group_id']);
                $t->index(['tenant_id', 'course_id']);
            });
        }
        if (! Schema::hasColumn('questions', 'question_bank_id')) {
            Schema::table('questions', function (Blueprint $t) {
                $t->foreignId('question_bank_id')->nullable()->after('course_id')->constrained('question_banks')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('questions', 'scoring')) {
            Schema::table('questions', function (Blueprint $t) {
                // per-question override of the exam's partial-credit rule: "all" (all or nothing) or "partial"
                $t->string('scoring', 16)->nullable()->after('mark');
            });
        }
        if (! Schema::hasColumn('question_imports', 'question_bank_id')) {
            Schema::table('question_imports', function (Blueprint $t) {
                $t->foreignId('question_bank_id')->nullable()->after('course_id')->constrained('question_banks')->nullOnDelete();
                $t->json('options')->nullable(); // where the questions go after saving (exam section), images uploaded with the file
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('question_imports', 'question_bank_id')) {
            Schema::table('question_imports', function (Blueprint $t) {
                $t->dropConstrainedForeignId('question_bank_id');
                $t->dropColumn('options');
            });
        }
        if (Schema::hasColumn('questions', 'scoring')) {
            Schema::table('questions', fn (Blueprint $t) => $t->dropColumn('scoring'));
        }
        if (Schema::hasColumn('questions', 'question_bank_id')) {
            Schema::table('questions', fn (Blueprint $t) => $t->dropConstrainedForeignId('question_bank_id'));
        }
        Schema::dropIfExists('question_banks');
    }
};
