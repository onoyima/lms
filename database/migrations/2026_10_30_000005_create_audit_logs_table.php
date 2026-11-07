<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tamper-evident log: each row stores the hash of the previous row in the same
        // institution's chain, so editing or deleting any row breaks every later hash.
        // Verify with: php artisan audit:verify
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');                 // module.toggled, question.imported ...
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at', 6);

            $table->index(['tenant_id', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
