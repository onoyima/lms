<?php

namespace Tests\Feature\Platform;

use App\Models\Coupon;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\TenantAddon;
use App\Models\User;
use App\Services\Billing\Billing;
use App\Services\Billing\CapacityExceeded;
use App\Services\Billing\Entitlements;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Platform\PlatformSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The subscription entitlement engine and billing: every capacity is enforced on the server from
 * values platform administrators edit (never code), and payments do exactly what their invoice is for.
 */
class EntitlementsBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    private User $admin;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        app(TenantProvisioner::class)->provision(['slug' => $this->tenant->slug, 'name' => $this->tenant->name], 'starter');
        $this->plan = Plan::where('key', 'starter')->first();
        $this->tenant->update(['plan_id' => $this->plan->id]);
        $this->admin = User::factory()->create(['user_type' => 'staff']);
        $this->admin->assignRole('institution_admin');
        $this->platform = User::factory()->create();
        $this->platform->forceFill(['tenant_id' => null, 'is_platform_admin' => true, 'user_type' => 'platform', 'two_factor_confirmed_at' => now()])->save();
        Notification::fake();
    }

    private function limits(array $limits): void
    {
        $this->plan->update(['limits' => $limits]);
        $this->tenant->unsetRelation('plan');
        Entitlements::forget($this->tenant->id);
    }

    private function sub(string $cycle = 'yearly'): Subscription
    {
        return app(Billing::class)->start($this->tenant, $this->plan, $cycle, null, 0);
    }

    private function students(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            User::factory()->create(['user_type' => 'student']);
        }
    }

    // ---------- capacities ----------

    public function test_student_and_staff_seats_are_enforced_and_inactive_accounts_do_not_count(): void
    {
        $this->limits(['students' => 2, 'staff' => 1]);
        $this->students(2);
        try {
            User::factory()->create(['user_type' => 'student']);
            $this->fail('a third student was allowed');
        } catch (CapacityExceeded $e) {
            $this->assertStringContainsString('allows 2 students', $e->getMessage());
        }
        // an inactive account uses no seat, but re-activating it needs one
        $off = User::factory()->create(['user_type' => 'student', 'status' => 'inactive']);
        $this->expectException(CapacityExceeded::class);
        $off->update(['status' => 'active']);
    }

    public function test_staff_seat_limit_counts_existing_staff(): void
    {
        $this->limits(['staff' => 1]); // the administrator already holds the only staff seat
        $this->expectException(CapacityExceeded::class);
        User::factory()->create(['user_type' => 'staff']);
    }

    public function test_a_platform_change_to_the_package_applies_at_once(): void
    {
        $this->limits(['students' => 1]);
        $this->students(1);
        $this->assertFalse(app(Entitlements::class)->allows('students'));
        $this->actingAs($this->platform)->put("/platform/plans/{$this->plan->id}", [
            'key' => 'starter', 'name' => 'Starter', 'currency' => 'NGN', 'price_monthly' => 75000, 'price_yearly' => 750000,
            'limits' => ['students' => 5, 'staff' => null], 'modules' => $this->plan->modules,
        ])->assertSessionHas('success');
        $this->assertSame(5, $this->plan->fresh()->limits['students']);
        $this->assertNull($this->plan->fresh()->limits['staff']);
        $this->tenant->unsetRelation('plan');
        $this->assertTrue(app(Entitlements::class)->allows('students'));
        $this->students(4);
    }

    public function test_enterprise_limits_replace_the_package_and_extra_capacity_adds_on_top(): void
    {
        $this->limits(['students' => 2]);
        $sub = $this->sub();
        $this->actingAs($this->platform)->post("/platform/institutions/{$this->tenant->id}/limits", ['limits' => ['students' => 3], 'note' => 'Contract 42'])->assertSessionHas('success');
        $this->assertSame(3, app(Entitlements::class)->limit('students', $this->tenant));
        $this->actingAs($this->platform)->post("/platform/institutions/{$this->tenant->id}/addons", ['limit_key' => 'students', 'quantity' => 10])->assertSessionHas('success');
        $this->assertSame(13, app(Entitlements::class)->limit('students', $this->tenant));
        $this->assertSame('Contract 42', $sub->fresh()->note);
        $this->assertTrue(SubscriptionEvent::where('type', 'limits_changed')->exists());
        // back to the package for that key
        $this->actingAs($this->platform)->post("/platform/institutions/{$this->tenant->id}/limits", ['limits' => [], 'use_package' => ['students']]);
        $this->assertSame(12, app(Entitlements::class)->limit('students', $this->tenant));
    }

    public function test_faculties_departments_and_administrators_are_limited(): void
    {
        $this->limits(['faculties' => 1, 'departments' => 1, 'admins' => 1]);
        $f = Faculty::create(['name' => 'Science', 'code' => 'SCI']);
        Department::create(['faculty_id' => $f->id, 'name' => 'Chemistry', 'code' => 'CHM']);
        foreach ([fn () => Faculty::create(['name' => 'Arts', 'code' => 'ART']), fn () => Department::create(['faculty_id' => $f->id, 'name' => 'Physics', 'code' => 'PHY'])] as $make) {
            try {
                $make();
                $this->fail('over the limit');
            } catch (CapacityExceeded) {
            }
        }
        $other = User::factory()->create(['user_type' => 'staff']);
        $this->expectException(CapacityExceeded::class);
        $other->assignRole('institution_admin'); // the one administrator seat is taken
    }

    public function test_a_capacity_refusal_on_a_form_comes_back_as_a_message(): void
    {
        $this->limits(['faculties' => 0]);
        $this->actingAs($this->admin)->from('/admin/academics')->post('/admin/academics/units/faculty', ['name' => 'Arts', 'code' => 'ART'])
            ->assertRedirect('/admin/academics')->assertSessionHas('error');
        $this->assertSame(0, Faculty::count());
    }

    public function test_upload_size_and_storage_are_enforced_on_the_server(): void
    {
        $this->limits(['upload_mb' => 1, 'storage_mb' => 100]);
        $this->actingAs($this->admin)->from('/settings/profile')
            ->patch('/settings/profile', ['name' => 'X', 'avatar' => UploadedFile::fake()->create('big.pdf', 2048)])
            ->assertRedirect('/settings/profile')->assertSessionHas('error');
        $this->assertFalse(app(Entitlements::class)->allows('upload_mb', 2));
        $this->assertTrue(app(Entitlements::class)->allows('upload_mb', 1));
        $this->assertFalse(app(Entitlements::class)->allows('storage_mb', 101));
    }

    public function test_api_requests_are_counted_per_month_and_refused_when_used_up(): void
    {
        $this->limits(['api_requests_monthly' => 2]);
        DB::table('api_keys')->insert(['tenant_id' => $this->tenant->id, 'name' => 'SIS', 'prefix' => 'abcdEFGH', 'key_hash' => hash('sha256', str_repeat('s', 40)),
            'scopes' => json_encode(['results.read']), 'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
        $key = 'vk_abcdEFGH_'.str_repeat('s', 40);
        $this->withHeaders(['Authorization' => "Bearer $key"])->getJson('/api/v1/results')->assertOk();
        $this->withHeaders(['Authorization' => "Bearer $key"])->getJson('/api/v1/results')->assertOk();
        $this->withHeaders(['Authorization' => "Bearer $key"])->getJson('/api/v1/results')->assertStatus(429)->assertJson(['error' => 'capacity_exceeded']);
        $this->assertSame(3, app(Entitlements::class)->usage('api_requests_monthly'));
    }

    public function test_module_allowance_is_enforced_and_temporary_grants_do_not_use_it_and_expire(): void
    {
        $this->sub();
        $this->limits(['modules' => count(app(Entitlements::class)->countedModules($this->tenant->fresh()))]);
        // one more module is refused
        $this->actingAs($this->platform)->withHeader('X-Inertia', 'true')->put("/platform/institutions/{$this->tenant->id}", ['overrides' => ['lms' => true]])->assertSessionHas('error');
        $this->assertFalse($this->tenant->moduleOverrides()->where('module', 'lms')->exists());
        // a temporary grant is allowed and switches itself off
        $this->actingAs($this->platform)->post("/platform/institutions/{$this->tenant->id}/grants", ['module' => 'lms', 'days' => 3, 'reason' => 'Pilot'])->assertSessionHas('success');
        $this->assertContains('lms', app(\App\Services\Modules\ModuleRegistry::class)->enabledFor($this->tenant->fresh()->load('plan', 'moduleOverrides')));
        $this->travel(4)->days();
        $done = app(Billing::class)->run();
        $this->assertSame(1, $done['grants_ended']);
        $this->assertNotContains('lms', app(\App\Services\Modules\ModuleRegistry::class)->enabledFor($this->tenant->fresh()->load('plan', 'moduleOverrides')));
        $this->assertTrue(SubscriptionEvent::where('type', 'module_revoked')->exists());
    }

    public function test_usage_meters_show_the_enforced_numbers(): void
    {
        $this->limits(['students' => 10]);
        $this->sub();
        $this->students(3);
        $this->actingAs($this->admin)->get('/institution/billing')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/billing')
            ->where('usage.0.key', 'students')->where('usage.0.used', 3)->where('usage.0.limit', 10)->where('usage.0.percent', 30));
        $this->actingAs($this->platform)->get("/platform/institutions/{$this->tenant->id}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('entitlements.0.used', 3)->has('history')->has('limitKeys'));
    }

    // ---------- billing ----------

    public function test_every_billing_period_has_its_price_and_length(): void
    {
        $this->plan->update(['price_monthly' => 100_00, 'price_quarterly' => null, 'price_biannual' => 500_00]);
        $start = CarbonImmutable::parse('2026-01-31');
        $b = app(Billing::class);
        $this->assertSame('2026-02-28', $b->periodEnd($start, 'monthly')->toDateString());
        $this->assertSame('2026-04-30', $b->periodEnd($start, 'quarterly')->toDateString());
        $this->assertSame('2026-07-31', $b->periodEnd($start, 'biannual')->toDateString());
        $this->assertSame('2027-01-31', $b->periodEnd($start, 'yearly')->toDateString());
        $this->assertSame(300_00, $this->plan->priceFor('quarterly'));
        $this->assertSame(500_00, $this->plan->priceFor('biannual'));
        $sub = $this->sub('quarterly');
        $this->assertSame(300_00, $sub->price);
        $this->assertStringContainsString('quarterly', Invoice::where('subscription_id', $sub->id)->sole()->lines[0]['description']);
    }

    public function test_trial_and_grace_come_from_the_console_settings(): void
    {
        $this->actingAs($this->platform)->put('/platform/settings', ['trial_days' => 10, 'grace_days' => 3, 'invoice_days_before' => 5, 'reminder_days' => [3, 1],
            'default_upload_mb' => 50, 'billing_name' => 'Veritas Ltd'])->assertSessionHas('success');
        $this->assertSame(10, PlatformSettings::get('trial_days'));
        $sub = app(Billing::class)->start($this->tenant, $this->plan, 'yearly');
        $this->assertSame('trialing', $sub->status);
        $this->assertSame(10, (int) round(now()->diffInDays($sub->trial_ends_at)));
        // a package can set its own
        $this->plan->update(['trial_days' => 0]);
        $this->assertSame('active', app(Billing::class)->start($this->tenant, $this->plan->fresh(), 'yearly')->status);
        $this->assertSame(3, Billing::graceDays($this->plan));
    }

    public function test_a_priced_trial_is_invoiced_and_becomes_past_due_when_unpaid(): void
    {
        PlatformSettings::put(['trial_days' => 5, 'invoice_days_before' => 14]);
        $sub = app(Billing::class)->start($this->tenant, $this->plan, 'yearly');
        app(Billing::class)->run();
        $this->assertSame(1, Invoice::where('subscription_id', $sub->id)->count());
        $this->travel(6)->days();
        app(Billing::class)->run();
        $this->assertSame('past_due', $sub->fresh()->status);
    }

    public function test_renewal_reminders_go_out_on_the_configured_days_once(): void
    {
        PlatformSettings::put(['reminder_days' => [3]]);
        $sub = $this->sub();
        $inv = Invoice::where('subscription_id', $sub->id)->sole();
        $inv->update(['due_at' => now()->startOfDay()->addDays(3)->setTime(12, 0)]); // three calendar days ahead, whatever the time now
        $this->assertSame(1, app(Billing::class)->run()['reminded']);
        $this->assertSame(0, app(Billing::class)->run()['reminded']);
        $this->assertNotNull($inv->fresh()->reminded_at);
    }

    public function test_a_discount_code_reduces_an_open_invoice_once(): void
    {
        $sub = $this->sub();
        $inv = Invoice::where('subscription_id', $sub->id)->sole();
        $full = $inv->amount;
        $this->actingAs($this->platform)->post('/platform/coupons', ['code' => 'launch20', 'kind' => 'percent', 'value' => 20, 'currency' => 'NGN', 'per_tenant' => 1, 'active' => true])->assertSessionHas('success');
        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true')->post("/institution/billing/invoices/{$inv->id}/coupon", ['code' => 'nope'])->assertSessionHas('error', 'That code is not valid.');
        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true')->post("/institution/billing/invoices/{$inv->id}/coupon", ['code' => 'LAUNCH20'])->assertSessionHas('success');
        $this->assertSame((int) round($full * 0.8), $inv->fresh()->amount);
        $this->assertSame(1, Coupon::where('code', 'LAUNCH20')->value('redeemed'));
        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true')->post("/institution/billing/invoices/{$inv->id}/coupon", ['code' => 'LAUNCH20'])->assertSessionHas('error');
        // the discounted amount pays the invoice in full
        app(Billing::class)->pay($inv->fresh(), 'bank_transfer', 'BANK-C1', $inv->fresh()->amount);
        $this->assertSame('paid', $inv->fresh()->status);
    }

    public function test_expired_or_restricted_codes_are_refused(): void
    {
        $sub = $this->sub();
        $inv = Invoice::where('subscription_id', $sub->id)->sole();
        Coupon::create(['code' => 'OLD', 'kind' => 'percent', 'value' => 10, 'ends_at' => now()->subDay()]);
        Coupon::create(['code' => 'MONTHLY', 'kind' => 'percent', 'value' => 10, 'cycles' => ['monthly']]);
        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true')->post("/institution/billing/invoices/{$inv->id}/coupon", ['code' => 'OLD'])->assertSessionHas('error', 'This code has expired.');
        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true')->post("/institution/billing/invoices/{$inv->id}/coupon", ['code' => 'MONTHLY'])->assertSessionHas('error', 'This code is not valid for this billing period.');
        $this->assertSame(0, $inv->fresh()->discount);
    }

    public function test_buying_extra_capacity_switches_it_on_when_paid(): void
    {
        $this->limits(['students' => 1]);
        $this->plan->update(['addon_prices' => ['students' => ['per' => 100, 'price' => 50_000_00]]]);
        $this->sub();
        $this->actingAs($this->admin)->post('/institution/billing/addons', ['key' => 'students', 'blocks' => 2])->assertSessionHas('success');
        $inv = Invoice::where('kind', 'addon')->sole();
        $this->assertSame(100_000_00, $inv->amount);
        $this->assertSame(1, app(Entitlements::class)->limit('students', $this->tenant)); // not before payment
        app(Billing::class)->pay($inv, 'bank_transfer', 'BANK-A1', $inv->amount);
        $this->assertSame(201, app(Entitlements::class)->limit('students', $this->tenant));
        $this->assertSame('active', TenantAddon::sole()->status);
        // a kind of capacity the package does not sell is refused
        $this->actingAs($this->admin)->withHeader('X-Inertia', 'true')->post('/institution/billing/addons', ['key' => 'staff', 'blocks' => 1])->assertSessionHas('error');
    }

    public function test_extra_capacity_ends_with_its_period(): void
    {
        $this->limits(['students' => 1]);
        TenantAddon::create(['tenant_id' => $this->tenant->id, 'limit_key' => 'students', 'quantity' => 5, 'status' => 'active', 'ends_at' => now()->addDays(2)]);
        Entitlements::forget($this->tenant->id);
        $this->assertSame(6, app(Entitlements::class)->limit('students', $this->tenant));
        $this->travel(3)->days();
        $this->assertSame(1, app(Billing::class)->run()['addons_ended']);
        $this->assertSame(1, app(Entitlements::class)->limit('students', $this->tenant));
    }

    public function test_an_upgrade_is_invoiced_with_credit_and_applied_once_paid(): void
    {
        $sub = $this->sub();
        app(Billing::class)->pay(Invoice::where('subscription_id', $sub->id)->sole(), 'bank_transfer', 'BANK-U0', $sub->price);
        $this->travel(6)->months();
        $pro = Plan::where('key', 'professional')->first();
        $this->actingAs($this->admin)->post('/institution/billing/change', ['plan_id' => $pro->id, 'cycle' => 'yearly'])->assertSessionHas('success');
        $inv = Invoice::where('kind', 'change')->sole();
        $this->assertLessThan($pro->price_yearly, $inv->amount); // about half the old year is credited
        $this->assertGreaterThan($pro->price_yearly - $sub->price, $inv->amount - 1);
        $this->assertSame('starter', $this->tenant->fresh()->plan->key);
        app(Billing::class)->pay($inv, 'bank_transfer', 'BANK-U1', $inv->amount);
        $this->assertSame('professional', $this->tenant->fresh()->plan->key);
        $new = Entitlements::current($this->tenant->id);
        $this->assertSame('active', $new->status);
        $this->assertSame(0, Invoice::where('subscription_id', $new->id)->where('status', 'open')->count());
        $this->assertTrue(SubscriptionEvent::where('type', 'plan_changed')->exists());
    }

    public function test_receipts_and_invoices_download_as_branded_pdfs_for_their_own_institution_only(): void
    {
        $sub = $this->sub();
        $inv = Invoice::where('subscription_id', $sub->id)->sole();
        $r = $this->actingAs($this->admin)->get("/institution/billing/invoices/{$inv->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Invoice-', $r->headers->get('Content-Disposition'));
        app(Billing::class)->pay($inv, 'bank_transfer', 'BANK-R1', $inv->amount);
        $r = $this->actingAs($this->admin)->get("/institution/billing/invoices/{$inv->id}/pdf")->assertOk();
        $this->assertStringContainsString('Receipt-', $r->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->actingAs($this->platform)->get("/platform/invoices/{$inv->id}/pdf")->assertOk();

        $other = app(TenantProvisioner::class)->provision(['slug' => 'other', 'name' => 'Other'], 'starter');
        $theirs = Invoice::where('subscription_id', app(Billing::class)->start($other, $this->plan, 'yearly', null, 0)->id)->sole();
        $this->actingAs($this->admin)->get("/institution/billing/invoices/{$theirs->id}/pdf")->assertNotFound();
        $this->actingAs($this->admin)->post("/institution/billing/invoices/{$theirs->id}/coupon", ['code' => 'X'])->assertNotFound();
    }

    public function test_extending_validity_restores_a_suspended_institution(): void
    {
        $sub = $this->sub();
        $this->travel(7 + Billing::graceDays($this->plan) + 1)->days();
        app(Billing::class)->run();
        $this->assertSame('suspended', $this->tenant->fresh()->status);
        $this->actingAs($this->platform)->post("/platform/institutions/{$this->tenant->id}/extend", ['days' => 30, 'reason' => 'Payment promised'])->assertSessionHas('success');
        $this->assertSame('active', $this->tenant->fresh()->status);
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertTrue(SubscriptionEvent::where('type', 'extended')->exists());
    }

    public function test_a_cancelled_subscription_expires_at_the_period_end_and_can_be_undone_before(): void
    {
        $sub = $this->sub();
        $this->actingAs($this->admin)->post('/institution/billing/cancel')->assertSessionHas('success');
        $this->assertTrue($sub->fresh()->cancel_at_period_end);
        $this->actingAs($this->admin)->post('/institution/billing/cancel', ['resume' => true]);
        $this->assertFalse($sub->fresh()->cancel_at_period_end);
        $this->actingAs($this->admin)->post('/institution/billing/cancel');
        $this->travel(13)->months();
        app(Billing::class)->run();
        $this->assertSame('expired', $sub->fresh()->status);
    }

    public function test_only_platform_administrators_manage_packages_codes_and_rules(): void
    {
        foreach (['/platform/settings', '/platform/coupons', '/platform/plans'] as $url) {
            $this->actingAs($this->platform)->get($url)->assertOk();
            $this->actingAs($this->admin)->get($url)->assertForbidden();
        }
        $this->actingAs($this->admin)->post("/platform/institutions/{$this->tenant->id}/limits", ['limits' => ['students' => 999999]])->assertForbidden();
        $this->actingAs($this->admin)->post("/platform/institutions/{$this->tenant->id}/addons", ['limit_key' => 'students', 'quantity' => 999])->assertForbidden();
        $this->actingAs($this->admin)->post("/platform/institutions/{$this->tenant->id}/extend", ['days' => 365])->assertForbidden();
        $this->assertSame(0, TenantAddon::count());
    }

    public function test_a_failed_paystack_payment_is_recorded_and_the_institution_told(): void
    {
        config(['platform.paystack.secret' => 'sk_test_x']);
        $sub = $this->sub();
        $inv = Invoice::where('subscription_id', $sub->id)->sole();
        \Illuminate\Support\Facades\Http::fake(['api.paystack.co/*' => \Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['status' => 'failed', 'amount' => $inv->amount, 'currency' => 'NGN', 'metadata' => ['invoice_id' => $inv->id], 'gateway_response' => 'Declined']])]);
        $this->actingAs($this->admin)->get('/institution/billing/callback?reference=VT'.$inv->id.'-abcdef123456')->assertRedirect('/institution/billing')->assertSessionHas('error');
        $this->assertSame('open', $inv->fresh()->status);
        $this->assertTrue(SubscriptionEvent::where('type', 'payment_failed')->exists());
    }
}
