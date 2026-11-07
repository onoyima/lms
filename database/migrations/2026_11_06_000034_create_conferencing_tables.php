<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live meetings and virtual classrooms on BigBlueButton. New tables only: nothing existing is changed.
 * People, courses and enrolments are the platform's own (foreign keys to users and course_offerings).
 */
return new class extends Migration
{
    public function up(): void
    {
        // BigBlueButton servers: the platform's shared ones (tenant_id NULL) or an institution's own
        Schema::create('conference_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('base_url', 255);              // https://bbb.example.edu/bigbluebutton/
            $table->text('secret');                       // encrypted; never sent to a browser
            $table->string('checksum_algo', 8)->default('sha256');
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('weight')->default(100);
            $table->unsignedInteger('max_participants')->nullable();
            $table->string('status', 12)->default('unknown'); // unknown, online, offline
            $table->string('version', 40)->nullable();
            $table->unsignedInteger('live_meetings')->default(0);
            $table->unsignedInteger('live_participants')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'enabled']);
        });

        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();                     // what links and pages use
            $table->string('bbb_meeting_id', 100)->unique();    // what BigBlueButton knows it by
            $table->foreignId('conference_server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('meetings')->nullOnDelete(); // recurring meetings
            $table->string('type', 20)->default('class');       // class, office_hours, tutorial, seminar, webinar, department, faculty, viva, practical, supervision, private
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('timezone', 64)->default('Africa/Lagos');
            $table->json('recurrence')->nullable();             // {"days":[1,3],"until":"2026-12-18"} on the first of a series
            $table->string('access', 12)->default('course');    // course, invited, institution
            $table->json('settings')->nullable();               // recording, waiting room, locks, limits
            $table->string('status', 12)->default('scheduled'); // scheduled, live, ended, cancelled
            $table->text('attendee_pw')->nullable();            // encrypted; for older BigBlueButton servers
            $table->text('moderator_pw')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'starts_at']);
            $table->index(['course_offering_id', 'starts_at']);
            $table->index(['tenant_id', 'status']);
        });

        // Who is invited, and with which meeting role (host, cohost, presenter, participant, examiner, proctor)
        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('participant');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['meeting_id', 'user_id']);
            $table->index('user_id');
        });

        // Raw events from BigBlueButton (webhooks and callbacks), kept once each
        Schema::create('meeting_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('event_key', 64)->unique();            // sha256 of the event: duplicates are ignored
            $table->string('type', 40);
            $table->string('internal_user_id', 100)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at', 3)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['meeting_id', 'occurred_at']);
        });

        // One row per time a person was in the meeting (join to leave); rebuilt from the events
        Schema::create('meeting_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('internal_user_id', 100);
            $table->string('name', 190)->nullable();
            $table->string('role', 12)->nullable();            // MODERATOR / VIEWER as BigBlueButton saw it
            $table->timestamp('joined_at', 3);
            $table->timestamp('left_at', 3)->nullable();
            $table->unsignedInteger('seconds')->default(0);
            $table->boolean('closed_by_meeting_end')->default(false);
            $table->timestamps();
            $table->unique(['meeting_id', 'internal_user_id', 'joined_at'], 'meeting_attendance_session');
            $table->index(['meeting_id', 'user_id']);
        });

        Schema::create('meeting_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->string('record_id', 120)->unique();
            $table->string('state', 12)->default('processing'); // processing, published, unpublished, deleted
            $table->string('format', 30)->nullable();
            $table->text('playback_url')->nullable();           // encrypted; only handed out through a signed, short-lived redirect
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('participants')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('visibility', 12)->default('attendees'); // hosts, attendees, institution
            $table->timestamps();
            $table->index(['meeting_id', 'state']);
        });
    }

    public function down(): void
    {
        foreach (['meeting_recordings', 'meeting_attendance', 'meeting_events', 'meeting_participants', 'meetings', 'conference_servers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
