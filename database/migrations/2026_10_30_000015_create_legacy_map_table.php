<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which old-portal row became which platform row, so `legacy:import`
 * can be re-run safely (new rows are added, existing ones are updated, nothing is duplicated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);          // student, examiner, exam, objective_question, criterion, attempt, ...
            $table->string('legacy_id', 80);
            $table->unsignedBigInteger('new_id');
            $table->timestamp('imported_at')->useCurrent();
            $table->unique(['tenant_id', 'kind', 'legacy_id']);
            $table->index(['tenant_id', 'kind', 'new_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_map');
    }
};
