<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix: candidate_messages.direction was 10 characters, but "to_candidate" is 12, so on MariaDB/MySQL in
 * strict mode every invigilator message, warning and lock notice to a candidate failed to save.
 * Widening the column keeps every existing row as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_messages', function (Blueprint $table) {
            $table->string('direction', 20)->change();
        });
    }

    public function down(): void
    {
        // never narrowed again: rows written since would be cut
    }
};
