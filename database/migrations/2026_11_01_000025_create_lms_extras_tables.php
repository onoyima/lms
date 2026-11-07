<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LMS extras: weekly class timetable, help centre and support tickets, private messages,
 * resumable uploads, similarity checks, reminder log, and calendar feed tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');      // 1 = Monday … 7 = Sunday
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('kind', 12)->default('lecture'); // lecture, practical, tutorial, seminar
            $table->string('venue', 120)->nullable();
            $table->string('group_label', 40)->nullable();  // e.g. "Group B"
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->index(['course_offering_id', 'weekday']);
        });

        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug', 120);
            $table->string('category', 60)->default('General');
            $table->string('audience', 10)->default('all'); // all, students, staff
            $table->longText('body_html');
            $table->boolean('published')->default(true);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('helpful')->default(0);
            $table->unsignedInteger('not_helpful')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('category', 20)->default('general'); // general, technical, exam, registration, results, account
            $table->string('priority', 8)->default('normal');    // low, normal, high, urgent
            $table->string('status', 10)->default('open');        // open, pending, resolved, closed
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('course_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'last_activity_at']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false); // staff-only notes
            $table->timestamp('created_at')->useCurrent();
            $table->index(['support_ticket_id', 'id']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->boolean('muted')->default(false);
            $table->primary(['conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason');
            $table->string('status', 10)->default('open');
            $table->timestamps();
        });

        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30);               // e.g. assignment:123
            $table->string('filename');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('received')->default(0);
            $table->string('path');
            $table->string('status', 10)->default('open'); // open, complete, used
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->decimal('similarity', 5, 2)->nullable()->after('file_hash'); // highest % overlap with another submission
            $table->json('similarity_matches')->nullable()->after('similarity');
            $table->timestamp('similarity_checked_at')->nullable()->after('similarity_matches');
        });

        Schema::create('reminder_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);                  // e.g. due24:item:55
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'key']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique(); // private iCal feed
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('calendar_token'));
        Schema::dropIfExists('reminder_log');
        Schema::table('assignment_submissions', fn (Blueprint $t) => $t->dropColumn(['similarity', 'similarity_matches', 'similarity_checked_at']));
        foreach (['upload_sessions', 'message_reports', 'messages', 'conversation_participants', 'conversations', 'ticket_messages', 'support_tickets', 'help_articles', 'class_schedules'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
