<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A course taught in a given semester. Shared by the LMS (course pages, content)
        // and by exams (candidate lists come from enrolments).
        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status', 20)->default('open'); // draft, open, closed, archived
            $table->timestamps();
            $table->unique(['course_id', 'semester_id']);
        });

        // Lecturers, coordinators and teaching assistants on an offering.
        Schema::create('course_offering_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30)->default('lecturer'); // coordinator, lecturer, assistant
            $table->timestamps();
            $table->unique(['course_offering_id', 'user_id']);
        });

        Schema::create('enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20)->default('core');  // core, elective, carryover
            $table->string('status', 20)->default('active');  // pending, active, dropped, completed
            $table->string('source', 20)->default('manual');  // manual, import, sis
            $table->timestamps();
            $table->unique(['course_offering_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolments');
        Schema::dropIfExists('course_offering_staff');
        Schema::dropIfExists('course_offerings');
    }
};
