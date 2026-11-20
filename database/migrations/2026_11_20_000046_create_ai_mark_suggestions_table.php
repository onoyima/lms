<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI marking suggestions, kept apart from the marks examiners give (attempt_answers.score): a suggestion
 * never becomes a mark by itself. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_mark_suggestions')) {
            Schema::create('ai_mark_suggestions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('question_id');
                $table->decimal('score', 8, 2);
                $table->decimal('out_of', 8, 2);
                $table->text('reasons')->nullable();
                $table->json('points')->nullable();       // what was credited / missing, point by point
                $table->string('model', 120)->nullable();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['attempt_id', 'question_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_mark_suggestions');
    }
};
