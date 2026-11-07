<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Computers allowed to sit an exam, recognised by their IP address on the campus network.
 * A new table only. Approvals are cleared after a sitting (or by the reset button) but the
 * rows are kept, so the exams office can always trace which computers were allowed or refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_computers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('ip', 45);
            $table->string('label', 80)->nullable();         // "Lab 2 – PC 14"
            $table->string('status', 12)->default('pending'); // pending, approved, rejected
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('requests')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('cleared_at')->nullable();      // set when approvals are wiped; the row stays as history
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('clear_reason', 190)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'cleared_at', 'ip']);
            $table->index(['tenant_id', 'cleared_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_computers');
    }
};
