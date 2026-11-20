<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exam links: one shareable link per sitting, with an optional access code. Depending on the
 * sitting's setting, students of the institution sign in, or anyone may take it by typing a
 * name (an exam-only guest account, like the panel examiner accounts). New tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exam_links')) {
            Schema::create('exam_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('exam_session_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('token', 40)->unique();
                $table->string('code_hash')->nullable();     // access code, if any
                $table->string('code_hint', 12)->nullable(); // shown to staff only
                $table->string('audience', 16)->default('institution'); // roster, institution, anyone
                $table->json('ask')->nullable();             // anyone: which details to ask (email, phone, identifier)
                $table->unsignedInteger('max_candidates')->nullable();
                $table->boolean('enabled')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('exam_link_entries')) {
            Schema::create('exam_link_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('exam_link_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('exam_candidate_id')->nullable()->constrained()->nullOnDelete();
                $table->boolean('guest')->default(false);
                $table->string('name', 120)->nullable();
                $table->string('email', 190)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('identifier', 60)->nullable(); // matric / registration number as typed
                $table->char('browser_hash', 64)->nullable(); // lets the same browser come back in
                $table->string('ip', 45)->nullable();
                $table->timestamps();
                $table->index(['exam_link_id', 'email']);
                $table->unique(['exam_link_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_link_entries');
        Schema::dropIfExists('exam_links');
    }
};
