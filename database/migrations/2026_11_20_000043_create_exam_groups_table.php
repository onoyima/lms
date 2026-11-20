<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One exam made of several papers (e.g. "Nursing Professional Exam": objective, theory, practical,
 * viva). A new table, plus one optional column on exams that says which exam a paper belongs to;
 * existing exams are untouched (the column stays empty for them).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exam_groups')) {
            Schema::create('exam_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('title');
                $table->string('code', 40)->nullable();
                $table->text('description')->nullable();
                $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['tenant_id', 'created_at']);
            });
        }
        if (! Schema::hasColumn('exams', 'exam_group_id')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->foreignId('exam_group_id')->nullable()->after('parent_exam_id')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('exams', 'exam_group_id')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->dropConstrainedForeignId('exam_group_id');
            });
        }
        Schema::dropIfExists('exam_groups');
    }
};
