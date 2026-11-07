<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription entitlements: what each package allows (capacities and modules), what an institution has
 * bought on top (extra capacity, temporary module access), enterprise overrides, coupons, every change
 * to a subscription, and the billing rules platform administrators control. Additive only: no column or
 * row is dropped; the existing student limit is carried into the new limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // {"students": 2000, "staff": 200, "admins": 5, "storage_mb": 20480, ...}; a missing or null key = unlimited
            $table->json('limits')->nullable()->after('student_limit');
            $table->unsignedBigInteger('price_quarterly')->nullable()->after('price_monthly'); // null = 3 x monthly
            $table->unsignedBigInteger('price_biannual')->nullable()->after('price_quarterly'); // null = 6 x monthly
            $table->unsignedSmallInteger('trial_days')->nullable()->after('price_yearly');      // null = platform default
            $table->unsignedSmallInteger('grace_days')->nullable()->after('trial_days');        // null = platform default
            $table->json('addon_prices')->nullable()->after('grace_days');                      // {"students": {"per": 100, "price": 500000, "cycle": "yearly"}}
        });
        // room for 'quarterly' and 'biannual' (widening only; existing values are untouched)
        Schema::table('subscriptions', fn (Blueprint $t) => $t->string('cycle', 12)->default('yearly')->change());
        Schema::table('institution_applications', fn (Blueprint $t) => $t->string('cycle', 12)->default('yearly')->change());
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('limits')->nullable()->after('student_limit'); // custom / enterprise overrides on top of the plan
            $table->string('note', 255)->nullable()->after('cancel_at_period_end');
        });
        Schema::table('tenant_modules', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('enabled'); // temporary access
            $table->string('reason', 190)->nullable()->after('expires_at');
            $table->foreignId('granted_by')->nullable()->after('reason')->constrained('users')->nullOnDelete();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('discount')->default(0)->after('amount');
            $table->string('kind', 12)->default('subscription')->after('status'); // subscription, change, addon, manual
            $table->json('meta')->nullable()->after('lines');                       // what paying it does: {"plan_id": 3, "cycle": "yearly"} / {"addon_id": 7}
            $table->timestamp('reminded_at')->nullable()->after('due_at');
        });

        Schema::create('tenant_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('limit_key', 40);              // students, staff, storage_mb, ...
            $table->unsignedBigInteger('quantity');
            $table->string('status', 10)->default('pending'); // pending (awaiting payment), active, ended
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();     // null = until the subscription ends
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 190)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'limit_key']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('description', 190)->nullable();
            $table->string('kind', 8)->default('percent'); // percent, fixed
            $table->unsignedBigInteger('value');            // percent (1-100) or minor units
            $table->char('currency', 3)->default('NGN');
            $table->json('plan_ids')->nullable();           // null = any plan
            $table->json('cycles')->nullable();             // null = any cycle
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('per_tenant')->default(1);
            $table->unsignedInteger('redeemed')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_off');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['coupon_id', 'tenant_id']);
        });

        // Every change to a subscription, for the history page and disputes
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);   // created, plan_changed, cycle_changed, renewed, extended, cancelled, resumed, expired, suspended, reactivated, limits_changed, addon_added, addon_ended, module_granted, module_revoked, coupon_applied, payment_failed, payment_succeeded
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });

        // Platform-wide rules the platform administrators change from the console
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // carry the existing student limit into the new limits
        foreach (DB::table('plans')->get(['id', 'student_limit']) as $p) {
            DB::table('plans')->where('id', $p->id)->update(['limits' => json_encode(['students' => $p->student_limit])]);
        }
        foreach (DB::table('subscriptions')->whereNotNull('student_limit')->get(['id', 'plan_id', 'student_limit']) as $s) {
            $planLimit = DB::table('plans')->where('id', $s->plan_id)->value('student_limit');
            if ((int) $planLimit !== (int) $s->student_limit) {
                DB::table('subscriptions')->where('id', $s->id)->update(['limits' => json_encode(['students' => $s->student_limit])]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('tenant_addons');
        $cols = array_values(array_filter(['discount', 'kind', 'meta', 'reminded_at'], fn ($c) => Schema::hasColumn('invoices', $c)));
        if ($cols) {
            Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn($cols));
        }
        Schema::table('tenant_modules', function (Blueprint $t) {
            $t->dropConstrainedForeignId('granted_by');
            $t->dropColumn(['expires_at', 'reason']);
        });
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn(['limits', 'note']));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['limits', 'price_quarterly', 'price_biannual', 'trial_days', 'grace_days', 'addon_prices']));
    }
};
