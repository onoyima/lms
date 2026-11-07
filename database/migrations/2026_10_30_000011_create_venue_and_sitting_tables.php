<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CBT halls and labs (Target 44, 47, 78, 99 multi-campus)
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('campus')->nullable();
            $table->unsignedInteger('capacity')->default(0);
            $table->string('network_cidr', 64)->nullable(); // e.g. 192.168.10.0/24 — exam traffic expected from here
            $table->timestamps();
        });

        // Whitelisted exam computers (Target 28, 163)
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->char('fingerprint_hash', 64); // sha256 of the device id the desktop app/SEB reports
            $table->string('public_key', 64)->nullable(); // Ed25519 key of the desktop exam app on this machine (signs exam requests)
            $table->string('status', 12)->default('approved'); // pending, approved, blocked
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'fingerprint_hash']);
        });

        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('label', 20); // A01
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['venue_id', 'label']);
        });

        // A sitting of an exam: date/time window, place, mode (Target 48, 88, 208)
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable(); // "Batch A"
            $table->string('mode', 10)->default('hall'); // hall, remote
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');            // nobody may start after this; deadlines never pass it unless extended
            $table->unsignedSmallInteger('late_entry_minutes')->default(15);
            $table->unsignedSmallInteger('grace_seconds')->default(30);
            $table->string('access_pin_hash')->nullable(); // optional session PIN given out in the hall (Target 57)
            $table->string('status', 12)->default('scheduled'); // scheduled, open, paused, closed
            $table->timestamp('paused_at')->nullable(); // emergency pause of the whole sitting (Target 42, 95)
            $table->boolean('is_practice')->default(false); // practice/dry-run (Target 81, 205)
            $table->timestamps();
            $table->index(['tenant_id', 'starts_at']);
            $table->index(['exam_id', 'status']);
        });

        // Who sits it, where, with which accommodations (Target 46, 56, 58, 83, 181)
        Schema::create('exam_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->nullable()->constrained()->nullOnDelete();
            $table->string('eligibility', 12)->default('eligible'); // eligible, ineligible, override
            $table->string('eligibility_reason')->nullable();
            $table->unsignedSmallInteger('extra_time_minutes')->default(0);
            $table->json('accommodations')->nullable(); // {"font_scale":1.25,"high_contrast":true,"screen_reader":true,"breaks":10}
            $table->string('pin_hash')->nullable();      // individual exam PIN / slip code
            $table->string('attendance', 12)->default('expected'); // expected, present, absent, excused
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_out_at')->nullable();
            $table->boolean('consent_given')->default(false); // monitoring notice accepted (Target 175)
            $table->timestamps();
            $table->unique(['exam_session_id', 'user_id']);
            $table->index(['user_id', 'exam_session_id']);
        });

        // Proctors/invigilators assigned to a sitting (Target 7, 55, 77, 79, 192)
        Schema::create('proctor_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('proctor'); // chief, invigilator, proctor, supervisor
            $table->unsignedSmallInteger('max_candidates')->default(30);
            $table->timestamp('last_seen_at')->nullable(); // proctor disconnection alerts (Target 78)
            $table->timestamps();
            $table->unique(['exam_session_id', 'user_id']);
        });

        // Which candidates each proctor watches (workload balancing / reassignment)
        Schema::create('proctor_candidate', function (Blueprint $table) {
            $table->foreignId('proctor_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_candidate_id')->constrained()->cascadeOnDelete();
            $table->primary(['proctor_assignment_id', 'exam_candidate_id']);
        });

        // Examiners who mark practical/viva/essay (legacy exam_examiners)
        Schema::create('exam_examiners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('examiner'); // examiner, moderator, external
            $table->timestamps();
            $table->unique(['exam_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_examiners');
        Schema::dropIfExists('proctor_candidate');
        Schema::dropIfExists('proctor_assignments');
        Schema::dropIfExists('exam_candidates');
        Schema::dropIfExists('exam_sessions');
        Schema::dropIfExists('seats');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('venues');
    }
};
