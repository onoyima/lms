<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Security monitoring (Target 216-219): failed logins, lockouts, refused API calls, privileged actions
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 40); // login_failed, login_locked, token_rejected, rate_limited, forbidden, privileged_action
            $table->string('ip', 45)->nullable();
            $table->string('path', 255)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['type', 'created_at']);
            $table->index(['ip', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('security_events');
    }
};
