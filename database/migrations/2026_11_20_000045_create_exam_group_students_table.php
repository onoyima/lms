<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Students assigned to a whole exam category (e.g. everyone sitting the Nursing professional exam):
 * they are added to every exam in the category, including exams added later. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exam_group_students')) {
            Schema::create('exam_group_students', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('exam_group_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['exam_group_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_group_students');
    }
};
