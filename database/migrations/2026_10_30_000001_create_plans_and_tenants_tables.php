<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A subscription package: a named preset of modules.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // starter, professional, enterprise
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('modules');                  // ["question_bank", "browser_proctoring", ...]
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // An institution using the platform. Every institution's data carries its tenant_id.
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();         // used in institution codes, QR links, X-Tenant
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->json('domains')->nullable();      // ["exams.example.edu.ng", "192.168.10.5"]
            $table->string('brand_color', 7)->default('#10b981');
            $table->string('logo_path')->nullable();
            $table->string('status')->default('active'); // active, suspended
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Per-institution overrides on top of the plan: add-ons switched on, or modules switched off.
        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('module');
            $table->boolean('enabled');
            $table->timestamps();
            $table->unique(['tenant_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};
