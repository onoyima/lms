<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administration: staff placement (which department/faculty an HOD or Dean oversees),
 * class streams, semester life-cycle, and approval of bulk personal-data exports
 * (LMS 11-25, 164-179, 196-213).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('faculty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 40)->nullable();      // Dr., Prof., Mr.
            $table->string('designation', 80)->nullable(); // Senior Lecturer
            $table->timestamps();
            $table->index(['tenant_id', 'department_id']);
            $table->index(['tenant_id', 'faculty_id']);
        });

        Schema::table('enrolments', function (Blueprint $table) {
            $table->string('stream', 20)->nullable()->after('category'); // class allocation, e.g. "A", "B"
        });

        Schema::table('semesters', function (Blueprint $table) {
            $table->string('status', 10)->default('planned')->after('is_current'); // planned, active, closed
            $table->timestamp('closed_at')->nullable()->after('status');
        });
        Schema::table('academic_sessions', function (Blueprint $table) {
            $table->string('status', 10)->default('planned')->after('is_current');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->string('learning_outcomes', 4000)->nullable()->after('description');
            $table->boolean('active')->default(true)->after('level');
        });

        // Periodic access reviews (LMS 214; Target 219): a reviewer confirms or removes each privileged role
        Schema::create('access_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('started_by')->constrained('users');
            $table->json('decisions')->nullable(); // [{user_id, role, keep, note}]
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('export_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report', 60);
            $table->json('filters')->nullable();
            $table->string('format', 5);
            $table->unsignedInteger('rows');
            $table->string('reason', 300);
            $table->string('status', 10)->default('pending'); // pending, approved, rejected, used
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_requests');
        Schema::dropIfExists('access_reviews');
        Schema::table('courses', fn (Blueprint $t) => $t->dropColumn(['learning_outcomes', 'active']));
        Schema::table('academic_sessions', fn (Blueprint $t) => $t->dropColumn('status'));
        Schema::table('semesters', fn (Blueprint $t) => $t->dropColumn(['status', 'closed_at']));
        Schema::table('enrolments', fn (Blueprint $t) => $t->dropColumn('stream'));
        Schema::dropIfExists('staff_profiles');
    }
};
