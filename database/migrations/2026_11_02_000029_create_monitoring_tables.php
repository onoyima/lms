<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Built-in monitoring (LMS 140, 143, 243, 245), exam-hall network checks (Target 162),
 * recovery drills (Target 186) and column encryption for sensitive personal notes (Target 86).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_metrics', function (Blueprint $table) {
            $table->id();
            $table->timestamp('minute')->unique();
            $table->json('data'); // requests, errors, p95, cpu, memory, db, queue, candidates…
        });

        Schema::create('system_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->string('level', 8);          // warning, critical
            $table->string('message', 500);
            $table->decimal('value', 12, 2)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['key', 'resolved_at']);
        });

        Schema::create('network_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('kind', 20);          // switch, access_point, router, server, internet, printer, other
            $table->string('host', 190);
            $table->unsignedSmallInteger('port')->default(80);
            $table->string('status', 8)->default('unknown'); // up, slow, down, unknown
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_up_at')->nullable();
            $table->timestamps();
        });

        Schema::create('network_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_device_id')->constrained()->cascadeOnDelete();
            $table->boolean('ok');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('checked_at')->index();
        });

        Schema::create('dr_drills', function (Blueprint $table) {
            $table->id();
            $table->string('status', 10)->default('running'); // running, passed, failed
            $table->json('steps')->nullable();
            $table->unsignedInteger('rto_seconds')->nullable();   // how long recovery took
            $table->unsignedInteger('rpo_seconds')->nullable();   // how much data could be lost
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });

        // sensitive notes are encrypted in the application, so a stolen database copy does not reveal them
        Schema::table('accommodation_profiles', fn (Blueprint $t) => $t->text('settings')->nullable()->change());
        foreach (DB::table('accommodation_profiles')->whereNotNull('settings')->get(['id', 'settings']) as $r) {
            DB::table('accommodation_profiles')->where('id', $r->id)->update(['settings' => Crypt::encryptString($r->settings)]);
        }
        foreach (DB::table('exception_requests')->get(['id', 'reason', 'decision_note']) as $r) {
            DB::table('exception_requests')->where('id', $r->id)->update(['reason' => Crypt::encryptString((string) $r->reason),
                'decision_note' => $r->decision_note === null ? null : Crypt::encryptString($r->decision_note)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('accommodation_profiles')->whereNotNull('settings')->get(['id', 'settings']) as $r) {
            DB::table('accommodation_profiles')->where('id', $r->id)->update(['settings' => Crypt::decryptString($r->settings)]);
        }
        foreach (['dr_drills', 'network_checks', 'network_devices', 'system_alerts', 'system_metrics'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
