<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Matching questions, accepted short answers (auto-marked) and learning outcomes (LMS 59-60; Target 19). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->json('matching')->nullable()->after('numeric_answer');         // {left:[{key,html}], right:[{key,html}], answer:{leftKey:rightKey}}
            $table->json('accepted_answers')->nullable()->after('matching');       // {answers:[...], case_sensitive:bool}
            $table->string('outcome', 190)->nullable()->after('topic');
            $table->index(['tenant_id', 'course_id', 'topic']);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'course_id', 'topic']);
            $table->dropColumn(['matching', 'accepted_answers', 'outcome']);
        });
    }
};
