<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Live media: recordings made by the media server, and quality samples for review (Target 4-7, 35-36, 111-124, 182). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);               // camera, screen, phone
            $table->string('egress_id', 64)->unique();
            $table->string('status', 12)->default('starting'); // starting, active, complete, failed
            $table->string('location')->nullable();  // file written by the media server
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['attempt_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_recordings');
    }
};
