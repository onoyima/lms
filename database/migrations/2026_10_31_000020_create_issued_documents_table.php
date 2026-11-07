<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every official PDF (result statement, transcript, slips) gets a code anyone can verify (LMS 23, 153, 163). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);               // result_statement, transcript, registration_slip, exam_slip
            $table->char('code', 16)->unique();
            $table->char('content_hash', 64);
            $table->string('summary', 255);
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_documents');
    }
};
