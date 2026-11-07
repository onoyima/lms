<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Examiner accounts made for one panel exam (viva, practical, research defence): first name, last name,
 * username and password. They open only that exam's scoring sheet (and its meeting room), stop working
 * when the exam is over, and are signed in on one computer at a time. Additive: a new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_panel_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('username', 60);
            $table->string('session_token', 64)->nullable();   // the one signed-in session (hashed)
            $table->timestamp('session_started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'username']);
            $table->index(['exam_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_panel_accounts');
    }
};
