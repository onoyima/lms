<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings history (Target 170), hardware exceptions (141), general exception requests (LMS 178),
 * e-mail delivery log (LMS 188), outside plagiarism checks (LMS 222), statistical anomaly flags
 * (Target 171) and lecture recordings (LMS 30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 60);                 // policy, campus:<name>, integrations
            $table->json('data');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->unsignedBigInteger('restored_from')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'scope', 'id']);
        });

        Schema::create('hardware_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();      // the candidate
            $table->foreignId('exam_id')->nullable()->constrained()->nullOnDelete(); // null = every exam until it expires
            $table->string('kind', 30);    // no_webcam, no_microphone, own_device, assistive_device, external_display, second_camera_exempt, other
            $table->text('detail');
            $table->json('relax')->nullable(); // proctoring settings switched off for this candidate
            $table->string('status', 10)->default('pending'); // pending, approved, rejected, revoked
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::create('exception_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);    // missed_exam, deadline, registration, accessibility, hardware, result, other
            $table->foreignId('course_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('exam_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('reason');
            $table->string('evidence_path')->nullable(); // private disk, encrypted
            $table->string('evidence_name')->nullable();
            $table->string('status', 10)->default('pending'); // pending, approved, rejected, withdrawn
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'type']);
        });

        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to');
            $table->string('subject')->nullable();
            $table->string('kind', 120)->nullable();     // the notification or mail class
            $table->string('status', 10)->default('sending'); // sending, sent, failed, bounced
            $table->string('message_id')->nullable()->index();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('plagiarism_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_submission_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 60);
            $table->string('token', 40)->unique();           // our reference, echoed back by the provider
            $table->string('external_id')->nullable();
            $table->string('status', 12)->default('queued'); // queued, sent, complete, failed
            $table->decimal('score', 5, 2)->nullable();      // similarity %
            $table->string('report_url', 500)->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attempt_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 6, 2);  // combined z-score style measure; higher = more unusual
            $table->json('reasons');
            $table->string('review', 12)->default('open'); // open, cleared, referred
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['attempt_id']);
        });

        Schema::table('class_meetings', function (Blueprint $table) {
            $table->string('recording_url', 500)->nullable();   // link to a recording on the institution's video service
            $table->string('recording_path')->nullable();       // or an uploaded file (private disk)
            $table->unsignedBigInteger('recording_size')->nullable();
            $table->boolean('recording_published')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('class_meetings', fn (Blueprint $t) => $t->dropColumn(['recording_url', 'recording_path', 'recording_size', 'recording_published']));
        foreach (['attempt_anomalies', 'plagiarism_checks', 'mail_logs', 'exception_requests', 'hardware_exceptions', 'settings_versions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
