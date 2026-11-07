<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Transcripts and their translations for live-class recordings. A new table only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_recording_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('meeting_transcripts')->cascadeOnDelete(); // a translation's original
            $table->string('kind', 12)->default('transcript'); // transcript, translation
            $table->string('language', 12)->nullable();
            $table->string('status', 12)->default('queued');  // queued, working, complete, failed
            $table->json('segments')->nullable();             // [{"start":1.2,"end":4.0,"text":"..."}]
            $table->longText('text')->nullable();
            $table->string('provider', 60)->nullable();
            $table->string('error', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['meeting_recording_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_transcripts');
    }
};
