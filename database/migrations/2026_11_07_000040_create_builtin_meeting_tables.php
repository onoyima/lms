<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Built-in meeting rooms (browsers connect to each other directly; the platform only introduces them)
 * and guest links for people without an account. New tables only; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // a link the host shares with outsiders (one per meeting), optionally with a passcode
        Schema::create('meeting_guest_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token', 48)->unique();
            $table->string('passcode_hash')->nullable();
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // someone who came in through a guest link (name they typed; no account)
        Schema::create('meeting_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('email', 190)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['meeting_id']);
        });

        // who is in a built-in room right now (one row per browser that joined)
        Schema::create('meeting_peers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('peer_id', 32)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meeting_guest_id')->nullable()->constrained('meeting_guests')->nullOnDelete();
            $table->string('name', 120);
            $table->string('role', 12);                    // host, moderator, participant, guest
            $table->string('state', 12)->default('in');     // waiting, in, left, removed, refused
            $table->boolean('audio')->default(true);
            $table->boolean('video')->default(true);
            $table->boolean('hand')->default(false);
            $table->boolean('screen')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->index(['meeting_id', 'state']);
        });

        // connection details passed between browsers (offers, answers, network candidates) and chat
        Schema::create('meeting_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('from_peer', 32);
            $table->string('to_peer', 32)->nullable();      // null = everyone in the room
            $table->string('type', 20);
            $table->mediumText('payload')->nullable();
            $table->timestamp('created_at', 3)->nullable();
            $table->index(['meeting_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['meeting_signals', 'meeting_peers', 'meeting_guests', 'meeting_guest_links'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
