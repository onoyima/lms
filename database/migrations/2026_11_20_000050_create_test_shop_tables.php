<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Test-series shop: packages of practice / mock exams, orders, vouchers and timed access. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('test_packages')) {
            Schema::create('test_packages', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('title', 160);
                $t->text('description')->nullable();
                $t->unsignedBigInteger('price');                // minor units (kobo, cents)
                $t->char('currency', 3)->default('NGN');
                $t->unsignedSmallInteger('access_days')->default(90);
                $t->boolean('active')->default(true);
                $t->unsignedSmallInteger('position')->default(0);
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('test_package_exams')) {
            Schema::create('test_package_exams', function (Blueprint $t) {
                $t->foreignId('test_package_id')->constrained()->cascadeOnDelete();
                $t->foreignId('exam_id')->constrained()->cascadeOnDelete();
                $t->primary(['test_package_id', 'exam_id']);
            });
        }
        if (! Schema::hasTable('vouchers')) {
            Schema::create('vouchers', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->string('code', 40);
                $t->string('kind', 10);                         // percent, amount, free
                $t->unsignedBigInteger('value')->default(0);    // percent, or minor units
                $t->foreignId('test_package_id')->nullable()->constrained()->cascadeOnDelete();
                $t->unsignedInteger('max_uses')->nullable();
                $t->unsignedInteger('used')->default(0);
                $t->timestamp('expires_at')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->unique(['tenant_id', 'code']);
            });
        }
        if (! Schema::hasTable('shop_orders')) {
            Schema::create('shop_orders', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('number', 24)->unique();
                $t->unsignedBigInteger('subtotal');
                $t->unsignedBigInteger('discount')->default(0);
                $t->unsignedBigInteger('total');
                $t->char('currency', 3);
                $t->string('status', 12)->default('pending');   // pending, paid, failed, refunded
                $t->string('provider', 12)->nullable();          // paystack, voucher, free
                $t->string('reference', 60)->nullable()->unique();
                $t->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
                $t->string('receipt_code', 16)->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->timestamps();
                $t->index(['tenant_id', 'status', 'created_at']);
            });
        }
        if (! Schema::hasTable('shop_order_items')) {
            Schema::create('shop_order_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('shop_order_id')->constrained()->cascadeOnDelete();
                $t->foreignId('test_package_id')->constrained()->cascadeOnDelete();
                $t->string('title', 160);
                $t->unsignedBigInteger('price');
            });
        }
        // dateTime, not timestamp: MySQL/MariaDB servers with explicit_defaults_for_timestamp off refuse a
        // second NOT NULL timestamp and silently add ON UPDATE CURRENT_TIMESTAMP to the first
        if (! Schema::hasTable('package_access')) {
            Schema::create('package_access', function (Blueprint $t) {
                $t->id();
                $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->foreignId('test_package_id')->constrained()->cascadeOnDelete();
                $t->foreignId('shop_order_id')->nullable()->constrained()->nullOnDelete();
                $t->dateTime('starts_at');
                $t->dateTime('expires_at');
                $t->timestamps();
                $t->index(['user_id', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (['package_access', 'shop_order_items', 'shop_orders', 'vouchers', 'test_package_exams', 'test_packages'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
