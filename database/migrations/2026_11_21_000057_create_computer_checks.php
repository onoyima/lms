<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Computer checks before an exam (each lab computer opens a link and is tested automatically) and the
 * problems computers report, with their IP address, during checks and real exams. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('computer_checks')) {
            Schema::create('computer_checks', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('name', 160);
                $t->string('code', 16)->unique();
                $t->foreignId('exam_session_id')->nullable()->constrained()->nullOnDelete();
                $t->json('requirements')->nullable();
                $t->dateTime('opens_at')->nullable();
                $t->dateTime('closes_at')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('computer_reports')) {
            Schema::create('computer_reports', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('computer_check_id')->nullable()->constrained()->cascadeOnDelete();
                $t->foreignId('exam_session_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('attempt_id')->nullable()->constrained()->cascadeOnDelete();
                $t->string('kind', 10)->default('check'); // check (before the exam) or exam (during one)
                $t->string('ip', 45);
                $t->string('label', 60)->nullable();      // computer name or seat typed in the lab
                $t->string('user_agent', 255)->nullable();
                $t->string('status', 10)->default('pass'); // pass, warn, fail
                $t->json('results')->nullable();
                $t->json('errors')->nullable();
                $t->unsignedInteger('error_count')->default(0);
                $t->dateTime('first_seen_at')->nullable();
                $t->dateTime('last_seen_at')->nullable();
                $t->timestamps();
                $t->index(['computer_check_id', 'ip']);
                $t->index(['tenant_id', 'kind', 'last_seen_at']);
                $t->index(['attempt_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_reports');
        Schema::dropIfExists('computer_checks');
    }
};
