<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Exam definition. One table set serves every exam format the current portal uses,
| plus the new ones:
|   cbt        objective computer-based test (legacy "objective"), auto-marked
|   essay      written/theory answers, marked by examiners
|   mixed      CBT and essay sections in one paper
|   practical  OSCE-style checklist: criteria with allowed scores (legacy "practical", e.g. "0,0.5,1")
|   viva       oral exam scored by a panel out of a total (legacy "viva")
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('code', 40)->nullable();
            $table->string('type', 20);                 // cbt, essay, mixed, practical, viva
            $table->string('category', 20)->default('exam'); // exam, test, quiz, practice, mock
            $table->text('instructions')->nullable();
            // draft -> submitted -> approved -> scheduled -> live -> closed -> results_published -> archived
            $table->string('status', 24)->default('draft');
            $table->decimal('total_mark', 8, 2)->nullable(); // scale raw score to this (legacy total_mark)
            $table->decimal('pass_mark', 8, 2)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedTinyInteger('max_attempts')->default(1);
            $table->string('score_policy', 10)->default('highest'); // highest, first, last, average
            $table->json('settings')->nullable();   // shuffle_questions, shuffle_options, show_score, show_review, calculator, navigation
            $table->json('proctoring')->nullable(); // policy: see App\Support\Exams\ProctoringPolicy
            $table->json('channels')->nullable();   // ["web","desktop","seb"]
            $table->string('marking_policy', 12)->default('average'); // practical/viva with several examiners: average, highest, first, median
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('legacy_ref')->nullable(); // id in the old EXAMS portal, for traceability
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'course_id']);
        });

        // A paper is made of sections; each section takes fixed questions and/or blueprint rules
        Schema::create('exam_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            // [{"topic":"Stoichiometry","difficulty":"easy","type":"single_choice","count":10,"mark":1}]
            $table->json('blueprint')->nullable();
            $table->unsignedSmallInteger('answer_any')->nullable(); // "answer any 3 of 5" (essay)
            $table->timestamps();
        });

        Schema::create('exam_section_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->decimal('mark', 6, 2)->nullable(); // override the question's mark
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['exam_section_id', 'question_id']);
        });

        // Practical checklist items and viva scoring heads (legacy `questions` with score_values)
        Schema::create('marking_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('text', 500);
            $table->json('allowed_scores')->nullable(); // [0, 0.5, 1]  (practical); null = any value 0..max_score (viva)
            $table->decimal('max_score', 6, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Frozen copies of an approved paper (Target 158: question paper version control)
        Schema::create('exam_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->char('checksum', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['exam_id', 'version']);
        });

        Schema::create('question_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot'); // stem, options with correctness, mark
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['question_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_versions');
        Schema::dropIfExists('exam_versions');
        Schema::dropIfExists('marking_criteria');
        Schema::dropIfExists('exam_section_questions');
        Schema::dropIfExists('exam_sections');
        Schema::dropIfExists('exams');
    }
};
