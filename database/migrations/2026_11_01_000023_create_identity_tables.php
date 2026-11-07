<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity: face enrolment and checks against the ID photo (biometric data, encrypted at rest,
 * consented, deletable), and single sign-on providers (SAML 2.0 and OpenID Connect).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('descriptor')->nullable();      // encrypted 128-number face template from enrolment
            $table->string('photo_path')->nullable();    // encrypted enrolment photo
            $table->text('id_descriptor')->nullable();   // encrypted template of the official ID photo
            $table->string('id_photo_path')->nullable(); // encrypted ID photo
            $table->decimal('id_distance', 5, 3)->nullable(); // enrolment vs ID photo (lower = more alike)
            $table->string('status', 12)->default('pending'); // pending, verified, rejected
            $table->string('note')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('identity_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 10);    // entry, periodic
            $table->string('result', 16);  // match, mismatch, no_face, multiple_faces, no_profile
            $table->decimal('distance', 5, 3)->nullable();
            $table->foreignId('evidence_file_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['attempt_id', 'id']);
        });

        Schema::create('sso_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);              // saml, oidc
            $table->string('name', 80);             // shown on the sign-in button
            $table->string('slug', 40);
            $table->text('config');                 // encrypted: endpoints, certificates, client secret
            $table->boolean('enabled')->default(false);
            $table->boolean('auto_create')->default(false); // create unknown users on first sign-in
            $table->string('default_user_type', 10)->default('student');
            $table->string('default_role', 40)->nullable();
            $table->boolean('link_by_email')->default(true); // first sign-in links to an existing account with that email
            $table->boolean('trust_idp_mfa')->default(false); // the IdP already enforces two-factor
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('sso_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sso_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 191);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['sso_provider_id', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_identities');
        Schema::dropIfExists('sso_providers');
        Schema::dropIfExists('identity_checks');
        Schema::dropIfExists('face_profiles');
    }
};
