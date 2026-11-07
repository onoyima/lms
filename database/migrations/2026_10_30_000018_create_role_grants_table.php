<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Temporary / delegated roles with an expiry date and a reason (LMS 8, 177; Target 53). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 60);
            $table->string('reason', 300);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamps();
            $table->index(['tenant_id', 'expires_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_grants');
    }
};
