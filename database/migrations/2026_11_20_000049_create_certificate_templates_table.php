<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificates and badges: templates the institution designs, awarded for passing an exam, an exam
 * category or a course. Awarded certificates are issued documents (verifiable at /verify/{code}).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('certificate_templates')) {
            Schema::create('certificate_templates', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('name', 120);
                $t->string('kind', 12)->default('certificate');     // certificate, badge
                $t->string('heading', 160)->default('Certificate of achievement');
                $t->text('body');                                     // with {name}, {exam}, {score}, {grade}, {date}, {institution}
                $t->string('signatory_name', 120)->nullable();
                $t->string('signatory_title', 120)->nullable();
                $t->string('colour', 7)->default('#1d3557');
                $t->string('badge_icon', 30)->nullable();             // award, star, medal, shield, book
                $t->decimal('min_percent', 5, 2)->default(50);
                $t->boolean('auto_award')->default(true);             // on results release, to everyone who reaches min_percent
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('certificate_awards')) {
            Schema::create('certificate_awards', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('certificate_template_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('subject_type', 20);                       // exam, exam_group, course
                $t->unsignedBigInteger('subject_id');
                $t->string('subject_title');
                $t->decimal('percent', 6, 2)->nullable();
                $t->string('grade', 6)->nullable();
                $t->char('code', 16)->unique();                       // the issued document's code
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();
                $t->unique(['certificate_template_id', 'user_id', 'subject_type', 'subject_id'], 'cert_award_once');
            });
        }
        if (! Schema::hasColumn('exams', 'certificate_template_id')) {
            Schema::table('exams', fn (Blueprint $t) => $t->foreignId('certificate_template_id')->nullable()->after('exam_group_id')->constrained()->nullOnDelete());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('exams', 'certificate_template_id')) {
            Schema::table('exams', fn (Blueprint $t) => $t->dropConstrainedForeignId('certificate_template_id'));
        }
        Schema::dropIfExists('certificate_awards');
        Schema::dropIfExists('certificate_templates');
    }
};
