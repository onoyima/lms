<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Institutional integration (Target 50, 100, 210-212; LMS 215-226): API keys for the
 * student information system and other systems to PUSH records in, a log of every sync
 * with per-row errors (reconciliation), and backup/restore-test records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->char('prefix', 8)->unique();       // shown to admins to identify the key
            $table->char('key_hash', 64);              // sha256 of the secret; the secret itself is never stored
            $table->json('scopes');                    // sis.write, results.read, ...
            $table->json('allowed_ips')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30);                // students, staff, courses, registrations, results_export
            $table->string('idempotency_key', 100)->nullable();
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->json('errors')->nullable();        // [{row, id, message}]
            $table->json('response')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'kind', 'idempotency_key']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12);                // backup, restore_test
            $table->string('status', 12);              // ok, failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('api_keys');
    }
};
