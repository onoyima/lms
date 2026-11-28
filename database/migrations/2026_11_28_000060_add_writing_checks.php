<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-writing score for assignment submissions, next to the similarity score that is already there,
 * and a note of whether the text of the hand-in could be read at all. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $t) {
            if (! Schema::hasColumn('assignment_submissions', 'ai_score')) {
                $t->decimal('ai_score', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('assignment_submissions', 'ai_signals')) {
                $t->json('ai_signals')->nullable();
            }
            if (! Schema::hasColumn('assignment_submissions', 'ai_checked_at')) {
                $t->dateTime('ai_checked_at')->nullable();
            }
            if (! Schema::hasColumn('assignment_submissions', 'text_status')) {
                $t->string('text_status', 20)->nullable(); // read | short | unreadable
            }
            if (! Schema::hasColumn('assignment_submissions', 'word_count')) {
                $t->unsignedInteger('word_count')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $t) {
            foreach (['ai_score', 'ai_signals', 'ai_checked_at', 'text_status', 'word_count'] as $c) {
                if (Schema::hasColumn('assignment_submissions', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
