<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification preferences, platform-wide announcements and audited support sign-ins.
 * Additive only: nothing existing is changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // {"course": {"mail": false}, "billing": {"app": true, "mail": true}} — security alerts ignore it
            $table->json('notification_preferences')->nullable();
        });

        Schema::create('platform_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 190);
            $table->text('body');
            $table->string('level', 10)->default('info');         // info, warning, critical
            $table->string('audience', 12)->default('everyone');  // everyone, admins, staff, students
            $table->json('tenant_ids')->nullable();                // null = every institution
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('dismissible')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['starts_at', 'ends_at']);
        });
        Schema::create('announcement_dismissals', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained('platform_announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['announcement_id', 'user_id']);
        });

        // Platform staff signing in as an institution user for support: who, as whom, why, when.
        Schema::create('support_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255);
            $table->string('ip', 45)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_sessions');
        Schema::dropIfExists('announcement_dismissals');
        Schema::dropIfExists('platform_announcements');
        if (Schema::hasColumn('users', 'notification_preferences')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_preferences'));
        }
    }
};
