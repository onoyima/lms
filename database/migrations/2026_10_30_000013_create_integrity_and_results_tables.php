<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Incident management and escalation (Target 32-33, 40, 99, 105, 109, 151-153, 191)
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);  // malpractice, technical, power_outage, network, identity, disruption, ai_alert
            $table->string('severity', 10)->default('medium'); // low, medium, high, critical
            $table->string('status', 14)->default('open');     // open, investigating, escalated, resolved, dismissed
            $table->string('source', 10)->default('proctor');  // system (AI / rules), proctor, candidate
            $table->boolean('needs_human_review')->default(false); // AI alerts are never final (Target 40)
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'severity']);
            $table->index(['exam_session_id', 'status']);
        });

        Schema::create('incident_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });

        // Evidence files with integrity hashes and restricted access (Target 34-39, 90-91, 174-180)
        Schema::create('evidence_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20); // snapshot, webcam_video, second_camera, screen, audio, document
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('mime', 60);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->boolean('encrypted')->default(true);
            $table->timestamp('captured_at', 3)->nullable();
            $table->timestamp('delete_after')->nullable(); // retention policy (Target 91, 180)
            $table->timestamps();
            $table->index(['attempt_id', 'kind']);
            $table->index('delete_after');
        });

        Schema::create('evidence_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evidence_file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 12); // view, download
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Results and their governance (Target 61, 149-162)
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('raw_score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->decimal('final_score', 8, 2)->nullable(); // after scaling to exam.total_mark
            $table->string('grade', 4)->nullable();
            // pending_marking, draft, moderated, approved, published, withheld
            $table->string('status', 16)->default('draft');
            $table->string('withheld_reason')->nullable(); // malpractice investigation etc. (Target 156-157)
            $table->boolean('locked')->default(false);     // final after approval (Target 162)
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['exam_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
        });

        // Any change to a locked/approved result needs a second person (Target 160, 161)
        Schema::create('result_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_id')->constrained()->cascadeOnDelete();
            $table->decimal('from_score', 8, 2)->nullable();
            $table->decimal('to_score', 8, 2);
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('pending'); // pending, approved, rejected
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        // Generic approval steps for exams (paper approval) and results (Target 49, 83-84, 151)
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->morphs('approvable');
            $table->string('step', 30);       // moderation, hod, dean, exams_office
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 10);   // approved, rejected, returned
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Appeals and rechecks (Target 41, 161)
        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('result_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12); // recheck, incident, accommodation
            $table->text('statement');
            $table->string('status', 12)->default('submitted'); // submitted, reviewing, upheld, rejected
            $table->text('decision')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        // Item analysis per exam (Target 66, 67, 160)
        Schema::create('item_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('responses');
            $table->decimal('difficulty', 5, 4)->nullable();     // proportion correct (p-value)
            $table->decimal('discrimination', 5, 4)->nullable(); // upper-lower 27% index
            $table->decimal('point_biserial', 5, 4)->nullable();
            $table->json('option_counts')->nullable();
            $table->timestamp('computed_at');
            $table->unique(['exam_id', 'question_id']);
        });

        // Accessibility & accommodation profiles kept per person (Target 82-83, 181)
        Schema::create('accommodation_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('extra_time_percent')->default(0);
            $table->json('settings')->nullable();
            $table->string('approved_reference')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['accommodation_profiles', 'item_statistics', 'appeals', 'approvals', 'result_changes', 'results',
            'evidence_access_logs', 'evidence_files', 'incident_notes', 'incidents'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
