<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reading passages / case studies shared by several questions, and question settings for the newer
 * types (fill in the blanks, ordering, file upload). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('question_passages')) {
            Schema::create('question_passages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
                $table->string('title');
                $table->longText('body_html');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('questions', 'passage_id')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->foreignId('passage_id')->nullable()->after('course_id')->constrained('question_passages')->nullOnDelete();
            });
        }
        if (! Schema::hasTable('attempt_files')) {
            Schema::create('attempt_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('question_id');
                $table->string('name');
                $table->string('mime', 120);
                $table->unsignedBigInteger('size');
                $table->string('path');
                $table->char('sha256', 64);
                $table->timestamps();
                $table->index(['attempt_id', 'question_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attempt_files');
        if (Schema::hasColumn('questions', 'passage_id')) {
            Schema::table('questions', fn (Blueprint $t) => $t->dropConstrainedForeignId('passage_id'));
        }
        Schema::dropIfExists('question_passages');
    }
};
