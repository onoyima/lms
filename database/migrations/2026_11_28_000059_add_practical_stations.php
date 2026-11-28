<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Practical exams for every discipline (additive only: nothing existing changes).
 *
 * - marking_criteria: grouped into stations; marked by a scale, done / not done, or a measured reading
 *   compared with the expected value and tolerance; critical steps; a guide for examiners.
 * - criterion_marks: the reading an examiner recorded.
 * - practical_evidence: work candidates hand in (code, photos, reports) and photos examiners take.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marking_criteria', function (Blueprint $table) {
            if (! Schema::hasColumn('marking_criteria', 'station')) {
                $table->string('station', 120)->nullable()->after('exam_id');
            }
            if (! Schema::hasColumn('marking_criteria', 'kind')) {
                $table->string('kind', 10)->default('score')->after('text'); // score | check | measure
            }
            if (! Schema::hasColumn('marking_criteria', 'expected')) {
                $table->decimal('expected', 14, 4)->nullable()->after('max_score');
                $table->decimal('tolerance', 14, 4)->nullable()->after('expected');
                $table->string('unit', 20)->nullable()->after('tolerance');
            }
            if (! Schema::hasColumn('marking_criteria', 'critical')) {
                $table->boolean('critical')->default(false)->after('unit');
            }
            if (! Schema::hasColumn('marking_criteria', 'guide')) {
                $table->string('guide', 1000)->nullable()->after('critical');
            }
        });

        Schema::table('criterion_marks', function (Blueprint $table) {
            if (! Schema::hasColumn('criterion_marks', 'reading')) {
                $table->decimal('reading', 14, 4)->nullable()->after('score');
            }
        });

        if (! Schema::hasTable('practical_evidence')) {
            Schema::create('practical_evidence', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // candidate
                $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
                $table->string('kind', 12)->default('candidate'); // candidate | examiner
                $table->string('file_path', 255);
                $table->string('file_name', 200);
                $table->string('file_mime', 120)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->string('note', 500)->nullable();
                $table->timestamps();
                $table->index(['exam_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practical_evidence');
        Schema::table('criterion_marks', function (Blueprint $table) {
            if (Schema::hasColumn('criterion_marks', 'reading')) {
                $table->dropColumn('reading');
            }
        });
        Schema::table('marking_criteria', function (Blueprint $table) {
            foreach (['station', 'kind', 'expected', 'tolerance', 'unit', 'critical', 'guide'] as $c) {
                if (Schema::hasColumn('marking_criteria', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
