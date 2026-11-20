<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A mobile number for text messages, and one-time sign-in codes. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'phone')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('phone', 32)->nullable()->after('email');
            });
        }
        if (! Schema::hasTable('login_codes')) {
            Schema::create('login_codes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('code_hash', 64);
                $t->string('channel', 10);              // email, sms
                $t->unsignedTinyInteger('tries')->default(0);
                $t->dateTime('expires_at');
                $t->timestamp('used_at')->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamps();
                $t->index(['user_id', 'expires_at']);
            });
        }
        if (! Schema::hasTable('sms_messages')) {
            Schema::create('sms_messages', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->string('to', 32);
                $t->string('purpose', 40);
                $t->string('status', 16);               // sent, failed
                $t->string('provider', 20);
                $t->string('provider_ref', 120)->nullable();
                $t->string('error', 255)->nullable();
                $t->unsignedSmallInteger('parts')->default(1);
                $t->timestamps();
                $t->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('login_codes');
        if (Schema::hasColumn('users', 'phone')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('phone'));
        }
    }
};
