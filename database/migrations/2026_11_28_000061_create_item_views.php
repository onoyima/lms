<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which student opened which course material, how often and when (for the lecturer's progress page). Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_views')) {
            return;
        }
        Schema::create('item_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('views')->default(1);
            $table->unsignedInteger('downloads')->default(0);
            $table->dateTime('first_viewed_at')->nullable();
            $table->dateTime('last_viewed_at')->nullable();
            $table->unique(['course_item_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_views');
    }
};
