<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One upload of a Word / text file, kept for traceability of every imported question.
        Schema::create('question_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('format', 10);             // docx, txt, md, html
            $table->string('status')->default('parsed'); // parsed, committed, failed
            $table->unsignedInteger('question_count')->default(0);
            $table->json('warnings')->nullable();
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('question_import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // single_choice, multiple_choice, true_false, numeric, short_answer, essay
            $table->string('type', 30)->default('single_choice');
            $table->longText('stem_html');            // sanitised HTML; maths kept as \( \) / \[ \] LaTeX or MathML
            $table->longText('explanation_html')->nullable();
            $table->string('topic')->nullable();
            $table->string('difficulty', 10)->nullable(); // easy, medium, hard
            $table->decimal('mark', 6, 2)->default(1);
            $table->string('status', 20)->default('draft'); // draft, in_review, approved, retired
            $table->unsignedInteger('version')->default(1);
            $table->json('numeric_answer')->nullable(); // {"value": 9.81, "tolerance": 0.01}
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'course_id', 'status']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('label', 5);               // A, B, C ...
            $table->longText('body_html');
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_imports');
    }
};
