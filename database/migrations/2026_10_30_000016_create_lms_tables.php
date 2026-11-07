<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Native LMS (no Moodle). Built on the existing course offerings and enrolments, so the
 * LMS and the exam system share one set of users, courses, question bank and results.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Student academic record (LMS 6, 15, 21, 167, 216) ─────────────────
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('level')->nullable();          // 100, 200 ...
            $table->string('academic_status', 12)->default('active');  // active, suspended, withdrawn, graduated, deferred
            $table->unsignedSmallInteger('entry_year')->nullable();
            $table->string('external_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'programme_id', 'level']);
        });

        Schema::create('course_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_id')->constrained('courses')->cascadeOnDelete();
            $table->unique(['course_id', 'prerequisite_id']);
        });

        // Which courses each programme/level takes, and how (LMS 13, 17)
        Schema::create('programme_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->string('category', 12)->default('core'); // core, elective
            $table->unique(['programme_id', 'course_id']);
            $table->index(['programme_id', 'level']);
        });

        // Registration window and rules per semester (LMS 16, 19, 24)
        Schema::create('registration_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->unique()->constrained()->cascadeOnDelete();
            $table->dateTime('opens_at');
            $table->dateTime('closes_at');
            $table->dateTime('late_closes_at')->nullable();
            $table->unsignedTinyInteger('min_units')->default(15);
            $table->unsignedTinyInteger('max_units')->default(24);
            $table->boolean('requires_approval')->default(false);
            $table->timestamps();
        });

        Schema::table('enrolments', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('source');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->string('note')->nullable()->after('approved_by');
            $table->index(['tenant_id', 'user_id', 'status']);
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->string('review_status', 12)->default('draft')->after('status'); // draft, submitted, approved (LMS 42)
            $table->foreignId('copied_from_id')->nullable()->after('review_status')->constrained('course_offerings')->nullOnDelete();
            $table->text('outline_html')->nullable();
        });

        // ── Course content (LMS 28-33, 39, 41) ────────────────────────────────
        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->dateTime('release_at')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();
            $table->index(['course_offering_id', 'position']);
        });

        // One table for materials and activities, so progress and release rules work the same for all
        Schema::create('course_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_section_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);             // page, file, link, video, live, assignment, quiz, forum
            $table->string('title');
            $table->longText('body_html')->nullable();
            $table->string('file_path')->nullable(); // private disk
            $table->string('file_name')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('url', 2000)->nullable();
            $table->foreignId('exam_id')->nullable()->constrained()->nullOnDelete(); // quiz → exam engine
            $table->foreignId('prerequisite_item_id')->nullable()->constrained('course_items')->nullOnDelete();
            $table->dateTime('release_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('cutoff_at')->nullable();  // no submissions after this, even late ones
            $table->json('settings')->nullable();       // max_score, late_penalty_pct, group, rubric, anonymous, file types...
            $table->boolean('visible')->default(true);
            $table->boolean('requires_completion')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['course_offering_id', 'course_section_id', 'position']);
            $table->index(['tenant_id', 'type', 'due_at']);
        });

        Schema::create('course_item_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['course_item_id', 'version']);
        });

        Schema::create('item_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at')->useCurrent();
            $table->unique(['course_item_id', 'user_id']);
            $table->index('user_id');
        });

        // ── Groups, assignments, extensions (LMS 37, 56-57, 64, 66-67, 69) ────
        Schema::create('course_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('course_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['course_group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_group_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->longText('text')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('file_hash', 64)->nullable();
            $table->string('anon_code', 12);
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_late')->default(false);
            $table->string('status', 12)->default('draft'); // draft, submitted, marked, returned
            $table->decimal('score', 7, 2)->nullable();       // after late penalty
            $table->decimal('raw_score', 7, 2)->nullable();
            $table->json('rubric_scores')->nullable();
            $table->longText('feedback_html')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->boolean('released')->default(false);
            $table->timestamps();
            $table->unique(['course_item_id', 'user_id', 'attempt']);
            $table->index(['course_item_id', 'status']);
            $table->index(['user_id', 'released']);
        });

        Schema::create('peer_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('score', 7, 2)->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_self')->default(false);
            $table->timestamps();
            $table->unique(['assignment_submission_id', 'reviewer_id']);
        });

        Schema::create('deadline_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('due_at');
            $table->string('reason', 500);
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamps();
            $table->unique(['course_item_id', 'user_id']);
        });

        // ── Forums and announcements (LMS 34-35, 53, 180) ─────────────────────
        Schema::create('forum_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->boolean('pinned')->default(false);
            $table->boolean('locked')->default(false);
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamp('last_post_at')->nullable();
            $table->timestamps();
            $table->index(['course_item_id', 'pinned', 'last_post_at']);
        });
        Schema::create('forum_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('forum_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('forum_posts')->nullOnDelete();
            $table->longText('body_html');
            $table->boolean('is_answer')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['forum_thread_id', 'id']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 12);                 // offering, department, faculty, institution
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('title');
            $table->longText('body_html');
            $table->boolean('urgent')->default(false);
            $table->dateTime('publish_at');
            $table->foreignId('author_id')->constrained('users');
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'scope', 'scope_id', 'publish_at']);
        });

        // ── Attendance (LMS 36, 82, 221) ──────────────────────────────────────
        Schema::create('class_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->dateTime('held_at');
            $table->string('topic')->nullable();
            $table->string('code', 8)->nullable();        // students self-mark with a short code
            $table->dateTime('code_expires_at')->nullable();
            $table->timestamps();
            $table->index(['course_offering_id', 'held_at']);
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 8)->default('present'); // present, late, absent, excused
            $table->string('source', 8)->default('staff');   // staff, code, import
            $table->timestamps();
            $table->unique(['class_meeting_id', 'user_id']);
            $table->index('user_id');
        });

        // ── Gradebook with CA / exam weighting (LMS 71, 147-148, 152) ─────────
        Schema::create('grade_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('name');                        // Continuous assessment, Examination
            $table->decimal('weight', 5, 2);               // percent of the course total
            $table->string('aggregation', 12)->default('mean'); // mean (of %), sum, best_n
            $table->unsignedTinyInteger('best_n')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('grade_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_category_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10);                  // item (assignment/quiz), exam, manual, attendance
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title');
            $table->decimal('max_score', 8, 2);
            $table->timestamps();
            $table->unique(['course_offering_id', 'source', 'source_id']);
        });
        Schema::create('grade_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 8, 2)->nullable();
            $table->boolean('overridden')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['grade_item_id', 'user_id']);
            $table->index('user_id');
        });
        Schema::create('course_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('total', 6, 2)->nullable();    // out of 100
            $table->string('grade', 4)->nullable();
            $table->json('breakdown')->nullable();
            $table->string('status', 12)->default('draft'); // draft, approved, released
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['course_offering_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['course_grades', 'grade_entries', 'grade_items', 'grade_categories', 'attendance_records', 'class_meetings', 'announcements',
            'forum_posts', 'forum_threads', 'deadline_extensions', 'peer_reviews', 'assignment_submissions', 'course_group_members', 'course_groups',
            'item_completions', 'course_item_revisions', 'course_items', 'course_sections', 'registration_windows', 'programme_courses',
            'course_prerequisites', 'student_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('copied_from_id');
            $table->dropColumn(['review_status', 'outline_html']);
        });
        Schema::table('enrolments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approved_at', 'note']);
        });
    }
};
