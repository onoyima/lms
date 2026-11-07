<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The hot path. Designed for thousands of simultaneous candidates:
|  - the candidate's paper is frozen once into attempt_papers (one-row read on every reload);
|  - answers are one row per (attempt, question), upserted with a client sequence number,
|    so retries and out-of-order saves are harmless (idempotent);
|  - the deadline lives on the attempt row (server-authoritative), so a server restart or a
|    dropped connection never changes it;
|  - proctoring events are append-only, indexed by (attempt_id, id) for cursor reads.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_candidate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_no')->default(1);
            // in_progress, paused, locked, submitted, auto_submitted, terminated
            $table->string('status', 16)->default('in_progress');
            $table->string('channel', 10)->default('web'); // web, desktop, seb
            $table->dateTime('started_at', 3);
            $table->dateTime('deadline_at', 3);          // server-authoritative end (Target 21, 134)
            $table->timestamp('submitted_at', 3)->nullable();
            $table->string('submit_reason', 20)->nullable(); // candidate, timeout, proctor, violations, terminated
            $table->timestamp('paused_at', 3)->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->char('token_hash', 64)->nullable();  // active session; a new device rotates it (Target 27, 75, 130)
            $table->char('device_hash', 64)->nullable(); // device binding (Target 28)
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_seen_at', 3)->nullable();
            $table->string('client_status', 16)->nullable(); // active, blur, offline, camera_off
            $table->unsignedSmallInteger('answered_count')->default(0);
            $table->unsignedSmallInteger('violation_count')->default(0);
            $table->unsignedSmallInteger('risk_score')->default(0); // 0-100 (Target 68)
            $table->decimal('score_raw', 8, 2)->nullable();
            $table->decimal('score_max', 8, 2)->nullable();
            $table->boolean('needs_marking')->default(false);
            $table->char('submission_hash', 64)->nullable(); // hash of all answers at submit (Target 157, 189)
            $table->timestamps();

            $table->unique(['exam_session_id', 'user_id', 'attempt_no']);
            $table->index(['exam_session_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'deadline_at']); // sweeper for expired attempts
        });

        Schema::create('attempt_papers', function (Blueprint $table) {
            $table->foreignId('attempt_id')->primary()->constrained()->cascadeOnDelete();
            $table->longText('payload'); // candidate-safe JSON: no correct answers
            $table->char('checksum', 64);
        });

        Schema::create('attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('question_version')->default(1);
            $table->unsignedBigInteger('exam_section_id')->nullable();
            $table->unsignedSmallInteger('position');
            $table->decimal('mark', 6, 2);
            $table->json('option_order')->nullable(); // option ids in the order shown
            $table->json('key')->nullable();          // correct option ids / numeric answer — server only
            $table->unique(['attempt_id', 'question_id']);
        });

        Schema::create('attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('question_id');
            $table->json('response')->nullable(); // {"options":[12]} | {"text":"..."} | {"number":9.8}
            $table->unsignedInteger('client_seq')->default(0); // highest sequence wins (Target 136, 188)
            $table->boolean('flagged')->default(false);
            $table->timestamp('answered_at', 3)->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->text('feedback')->nullable();
            $table->unique(['attempt_id', 'question_id']);
        });

        Schema::create('proctoring_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);       // tab_hidden, focus_lost, fullscreen_exit, copy_blocked, face_absent, multiple_faces, display_change ...
            $table->unsignedTinyInteger('severity')->default(1); // 1 info, 2 low, 3 medium, 4 high, 5 critical
            $table->json('data')->nullable();
            $table->timestamp('client_time', 3)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();
            $table->index(['attempt_id', 'id']);
            $table->index(['tenant_id', 'created_at']);
        });

        // Proctor -> candidate messages that must be acknowledged (Target 6, 84, 154)
        Schema::create('candidate_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 10); // to_candidate, to_proctor
            $table->string('kind', 12)->default('message'); // message, warning, help
            $table->text('body');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['attempt_id', 'id']);
        });

        // Practical / viva scores per criterion per examiner (legacy `answers`)
        Schema::create('criterion_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marking_criterion_id')->constrained('marking_criteria')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();     // candidate
            $table->foreignId('examiner_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('score', 6, 2);
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['marking_criterion_id', 'user_id', 'examiner_id']);
            $table->index(['exam_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('criterion_marks');
        Schema::dropIfExists('candidate_messages');
        Schema::dropIfExists('proctoring_events');
        Schema::dropIfExists('attempt_answers');
        Schema::dropIfExists('attempt_questions');
        Schema::dropIfExists('attempt_papers');
        Schema::dropIfExists('attempts');
    }
};
