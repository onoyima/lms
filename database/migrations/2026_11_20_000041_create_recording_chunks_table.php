<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pieces of camera/screen recordings made in the candidate's browser (no media server needed).
 * New table only; existing tables are not touched. Each recording is a media_recordings row
 * whose egress_id starts with "web:".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recording_chunks')) {
            return;
        }
        Schema::create('recording_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_recording_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('disk', 32);
            $table->string('path');
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->unsignedInteger('offset_ms')->default(0); // from the start of the recording
            $table->timestamp('created_at')->nullable();
            $table->unique(['media_recording_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_chunks');
    }
};
