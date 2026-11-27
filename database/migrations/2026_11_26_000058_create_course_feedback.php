<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What students say about a course, and the course's own notebook for each student:
 *  - course reviews (stars and a comment, one per student, answered by the lecturer);
 *  - polls the lecturer runs in a course;
 *  - requests for topics or material the course has not covered ("me too" votes);
 *  - each student's private notes and bookmarks on course materials.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_reviews')) {
            Schema::create('course_reviews', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->unsignedTinyInteger('rating');
                $t->text('comment')->nullable();
                $t->boolean('anonymous')->default(false);
                $t->text('reply')->nullable();
                $t->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('replied_at')->nullable();
                $t->boolean('hidden')->default(false);
                $t->timestamps();
                $t->unique(['course_offering_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('course_polls')) {
            Schema::create('course_polls', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
                $t->string('question', 300);
                $t->json('options');
                $t->boolean('multiple')->default(false);
                $t->boolean('anonymous')->default(true);
                $t->string('show_results', 12)->default('after_vote'); // after_vote, after_close, teacher
                $t->dateTime('closes_at')->nullable();
                $t->dateTime('closed_at')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('course_poll_votes')) {
            Schema::create('course_poll_votes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('course_poll_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->json('choices');
                $t->timestamps();
                $t->unique(['course_poll_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('topic_requests')) {
            Schema::create('topic_requests', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('kind', 12)->default('topic'); // topic, material, explanation
                $t->string('title', 200);
                $t->text('details')->nullable();
                $t->string('status', 12)->default('open'); // open, planned, covered, declined
                $t->text('reply')->nullable();
                $t->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
                $t->dateTime('replied_at')->nullable();
                $t->foreignId('course_item_id')->nullable()->constrained()->nullOnDelete();
                $t->unsignedInteger('votes')->default(0);
                $t->timestamps();
                $t->index(['course_offering_id', 'status']);
            });
        }
        if (! Schema::hasTable('topic_request_votes')) {
            Schema::create('topic_request_votes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('topic_request_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['topic_request_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('course_item_notes')) {
            Schema::create('course_item_notes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('course_item_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->text('body')->nullable();
                $t->boolean('bookmarked')->default(false);
                $t->timestamps();
                $t->unique(['course_item_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['course_item_notes', 'topic_request_votes', 'topic_requests', 'course_poll_votes', 'course_polls', 'course_reviews'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
