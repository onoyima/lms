<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own business: institutions applying to join, subscriptions to plans,
 * invoices and payments. Owned by the platform, not by any institution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('tagline')->nullable()->after('name');
            $table->char('currency', 3)->default('NGN')->after('modules');
            $table->unsignedBigInteger('price_monthly')->default(0)->after('currency'); // minor units (kobo)
            $table->unsignedBigInteger('price_yearly')->default(0)->after('price_monthly');
            $table->unsignedInteger('student_limit')->nullable()->after('price_yearly'); // null = unlimited
            $table->json('features')->nullable()->after('student_limit');            // marketing bullet points
            $table->boolean('is_public')->default(true)->after('features');
            $table->boolean('highlighted')->default(false)->after('is_public');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('short_name');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->string('contact_phone', 40)->nullable()->after('contact_email');
            $table->string('suspension_reason', 40)->nullable()->after('status'); // non_payment, request, abuse
            $table->timestamp('approved_at')->nullable();
        });

        Schema::create('institution_applications', function (Blueprint $table) {
            $table->id();
            $table->string('institution');
            $table->string('slug', 40);
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone', 40)->nullable();
            $table->string('country', 60)->default('Nigeria');
            $table->unsignedInteger('students')->nullable();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cycle', 8)->default('yearly');
            $table->text('message')->nullable();
            $table->string('status', 10)->default('pending'); // pending, approved, rejected
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('cycle', 8)->default('yearly');   // monthly, yearly
            $table->string('status', 10)->default('trialing'); // trialing, active, past_due, cancelled, expired
            $table->unsignedBigInteger('price');               // per cycle, minor units
            $table->char('currency', 3)->default('NGN');
            $table->unsignedInteger('student_limit')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();
            $table->index(['status', 'current_period_end']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->unique();
            $table->string('status', 8)->default('open'); // open, paid, void
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('NGN');
            $table->json('lines');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 16);              // paystack, bank_transfer, manual
            $table->string('reference', 100)->unique();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 10)->default('pending'); // pending, succeeded, failed, refunded
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // platform administrators are not institution staff
        DB::table('users')->where('is_platform_admin', true)->whereNull('tenant_id')->update(['user_type' => 'platform']);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('institution_applications');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['contact_name', 'contact_email', 'contact_phone', 'suspension_reason', 'approved_at']));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['tagline', 'currency', 'price_monthly', 'price_yearly', 'student_limit', 'features', 'is_public', 'highlighted']));
    }
};
