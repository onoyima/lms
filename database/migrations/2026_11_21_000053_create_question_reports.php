<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Students report a faulty question from the exam screen; examiners settle each report. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('question_reports')) {
            return;
        }
        Schema::create('question_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $t->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('question_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('reason', 32);       // wrong_key, two_answers, no_answer, unclear, missing_picture, other
            $t->text('note')->nullable();
            $t->string('status', 16)->default('open'); // open, resolved, dismissed
            $t->string('resolution', 32)->nullable();  // full_mark, drop, fix_key, dismissed
            $t->text('response')->nullable();          // what the examiner decided, in words
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['attempt_id', 'question_id']);
            $t->index(['tenant_id', 'exam_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_reports');
    }
};
