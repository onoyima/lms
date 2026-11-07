<?php

namespace Tests\Feature\Platform;

use App\Models\InstitutionApplication;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Billing;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The platform operator and the institutions are kept apart: platform administrators run
 * institutions, plans and billing, and cannot open any institution's data; institution
 * people cannot reach the platform console.
 */
class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        app(TenantProvisioner::class)->provision(['slug' => $this->tenant->slug, 'name' => $this->tenant->name], 'enterprise');
        $this->admin = User::factory()->create(['user_type' => 'staff']);
        $this->admin->assignRole('institution_admin');
        $this->platform = User::factory()->create();
        $this->platform->forceFill(['tenant_id' => null, 'is_platform_admin' => true, 'user_type' => 'platform', 'two_factor_confirmed_at' => now()])->save();
        Notification::fake();
    }

    private function student(): User
    {
        $u = User::factory()->create(['user_type' => 'student']);
        $u->assignRole('student');

        return $u;
    }

    public function test_platform_admins_cannot_open_institution_pages(): void
    {
        foreach (['/dashboard', '/admin/people', '/exams', '/question-bank', '/learn/courses', '/admin/reports', '/institution/billing'] as $url) {
            $this->actingAs($this->platform)->get($url)->assertRedirect('/platform');
        }
        $this->actingAs($this->platform)->getJson('/admin/people')->assertForbidden();
        // their own account settings still work
        $this->actingAs($this->platform)->get('/settings/profile')->assertOk();
    }

    public function test_platform_admins_hold_no_institution_permissions(): void
    {
        $this->actingAs($this->platform)->get('/platform')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('platform/overview')->where('auth.permissions', [])->where('auth.isPlatformAdmin', true));
        $this->assertFalse($this->platform->can('users.manage'));
        $this->assertFalse($this->platform->can('institution.manage'));
    }

    public function test_the_console_loads_no_institution_records(): void
    {
        $this->actingAs($this->platform)->get('/platform/institutions')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('platform/institutions')->has('institutions', 1)
                ->where('institutions.0.usage.staff', 1) // counts only
                ->missing('institutions.0.users'));
        foreach (['/platform/institutions/'.$this->tenant->id, '/platform/applications', '/platform/plans', '/platform/payments', '/platform/admins', '/platform/modules'] as $url) {
            $this->actingAs($this->platform)->get($url)->assertOk();
        }
    }

    public function test_institution_people_cannot_reach_the_console(): void
    {
        foreach ([$this->admin, $this->student()] as $u) {
            foreach (['/platform', '/platform/institutions', '/platform/plans', '/platform/payments'] as $url) {
                $this->actingAs($u)->get($url)->assertForbidden();
            }
            $this->actingAs($u)->post('/platform/institutions', [])->assertForbidden();
        }
    }

    public function test_only_the_institution_administrator_sees_billing(): void
    {
        $this->actingAs($this->admin)->get('/institution/billing')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/billing'));
        $ict = User::factory()->create(['user_type' => 'staff']);
        $ict->assignRole('ict_admin'); // manages settings, not the contract
        $this->actingAs($ict)->get('/institution/billing')->assertForbidden();
        $this->actingAs($this->student())->get('/institution/billing')->assertForbidden();
    }

    public function test_platform_sign_in_lands_on_the_console(): void
    {
        $this->platform->forceFill(['password' => 'Secret!2345678'])->save();
        $this->post('/login', ['email' => $this->platform->email, 'password' => 'Secret!2345678'])->assertRedirect('/platform');
    }

    public function test_creating_an_institution_starts_a_trial_and_invites_its_administrator(): void
    {
        $plan = Plan::where('key', 'starter')->first();
        $this->actingAs($this->platform)->post('/platform/institutions', [
            'name' => 'Lagos Polytechnic', 'slug' => 'lagospoly', 'domains' => ['exams.lagospoly.edu.ng'], 'plan_id' => $plan->id, 'cycle' => 'yearly',
            'trial_days' => 30, 'admin_name' => 'Ada Obi', 'admin_email' => 'ICT@lagospoly.edu.ng',
        ])->assertRedirect();

        $t = Tenant::where('slug', 'lagospoly')->firstOrFail();
        $this->assertSame($plan->id, $t->plan_id);
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $t->id, 'status' => 'trialing']);
        $u = User::withoutGlobalScopes()->where('tenant_id', $t->id)->where('email', 'ict@lagospoly.edu.ng')->firstOrFail();
        app(TenantContext::class)->run($t, fn () => $this->assertTrue($u->hasRole('institution_admin')));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.institution_created', 'tenant_id' => null]);
    }

    public function test_an_application_from_the_website_can_be_approved(): void
    {
        $this->post('/apply', ['institution' => 'Bells University', 'slug' => 'bells', 'contact_name' => 'Tola', 'contact_email' => 'Reg@Bells.edu.ng',
            'cycle' => 'yearly', 'plan_id' => Plan::where('key', 'professional')->value('id'), 'students' => 4000])->assertRedirect();
        // a bot filling the hidden field is thanked and ignored
        $this->post('/apply', ['institution' => 'Spam', 'slug' => 'spam', 'contact_name' => 'x', 'contact_email' => 'x@x.com', 'cycle' => 'yearly', 'website' => 'http://spam'])->assertRedirect();

        $app = InstitutionApplication::sole();
        $this->assertSame('reg@bells.edu.ng', $app->contact_email);
        $this->actingAs($this->platform)->post("/platform/applications/{$app->id}", ['approve' => true, 'trial_days' => 14])->assertRedirect();

        $this->assertSame('approved', $app->fresh()->status);
        $t = Tenant::where('slug', 'bells')->firstOrFail();
        $this->assertSame($t->id, $app->fresh()->tenant_id);
        $this->assertTrue(User::withoutGlobalScopes()->where('tenant_id', $t->id)->where('email', 'reg@bells.edu.ng')->exists());
        $this->actingAs($this->platform)->post("/platform/applications/{$app->id}", ['approve' => true])->assertStatus(409);
    }

    public function test_a_bank_transfer_pays_the_invoice_once(): void
    {
        $sub = app(Billing::class)->start($this->tenant, Plan::where('key', 'starter')->first(), 'yearly', null, 0);
        $inv = Invoice::where('subscription_id', $sub->id)->sole();
        $this->assertSame('open', $inv->status);

        $pay = fn () => $this->actingAs($this->platform)->post("/platform/invoices/{$inv->id}/payment", ['reference' => 'GTB-778', 'amount' => $inv->amount / 100, 'provider' => 'bank_transfer']);
        $pay()->assertRedirect();
        $pay()->assertRedirect(); // the same transfer entered twice

        $this->assertSame(1, Payment::count());
        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame('active', $sub->fresh()->status);
        $this->actingAs($this->platform)->post("/platform/invoices/{$inv->id}/void")->assertStatus(409);
    }

    public function test_too_small_a_payment_is_refused(): void
    {
        $sub = app(Billing::class)->start($this->tenant, Plan::where('key', 'starter')->first(), 'yearly', null, 0);
        $inv = Invoice::where('subscription_id', $sub->id)->sole();
        $this->actingAs($this->platform)->post("/platform/invoices/{$inv->id}/payment", ['reference' => 'X1', 'amount' => 10, 'provider' => 'bank_transfer'])->assertStatus(422);
        $this->assertSame('open', $inv->fresh()->status);
    }

    public function test_paystack_webhook_needs_a_valid_signature_and_is_checked_with_paystack(): void
    {
        config(['platform.paystack.secret' => 'sk_test_x']);
        $sub = app(Billing::class)->start($this->tenant, Plan::where('key', 'starter')->first(), 'monthly', null, 0);
        $inv = Invoice::where('subscription_id', $sub->id)->sole();
        $ref = 'VT'.$inv->id.'-abcdef012345';
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'amount' => $inv->amount, 'currency' => 'NGN', 'reference' => $ref, 'metadata' => ['invoice_id' => $inv->id]]])]);
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $ref]]);

        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'forged'], $body)->assertStatus(401);
        $this->assertSame('open', $inv->fresh()->status);

        $sig = hash_hmac('sha512', $body, 'sk_test_x');
        foreach ([1, 2] as $_) { // Paystack retries; the payment is recorded once
            $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $sig], $body)->assertOk();
        }
        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame(1, Payment::where('reference', $ref)->count());
    }

    public function test_an_institution_cannot_pay_or_see_another_institutions_invoice(): void
    {
        config(['platform.paystack.secret' => 'sk_test_x']);
        $other = app(TenantProvisioner::class)->provision(['slug' => 'other', 'name' => 'Other'], 'starter');
        $sub = app(Billing::class)->start($other, Plan::where('key', 'starter')->first(), 'yearly', null, 0);
        $inv = Invoice::where('subscription_id', $sub->id)->sole();

        $this->actingAs($this->admin)->post("/institution/billing/invoices/{$inv->id}/pay")->assertNotFound();
        $this->actingAs($this->admin)->get('/institution/billing')->assertInertia(fn (Assert $p) => $p->has('invoices', 0));
    }

    public function test_unpaid_institutions_are_suspended_and_paying_restores_them(): void
    {
        $sub = app(Billing::class)->start($this->tenant, Plan::where('key', 'starter')->first(), 'yearly', null, 0);
        $inv = Invoice::where('subscription_id', $sub->id)->sole();
        $this->travel(7 + Billing::graceDays() + 1)->days();
        app(Billing::class)->run();

        $this->assertSame('suspended', $this->tenant->fresh()->status);
        $this->assertSame('past_due', $sub->fresh()->status);

        // students and staff see the suspended page; the administrator is sent to billing and can pay
        $this->actingAs($this->student())->get('/dashboard')->assertStatus(403)->assertInertia(fn (Assert $p) => $p->component('suspended'));
        $this->actingAs($this->admin)->get('/dashboard')->assertRedirect('/institution/billing');
        $this->actingAs($this->admin)->get('/institution/billing')->assertOk();

        app(Billing::class)->pay($inv, 'bank_transfer', 'BANK-1', $inv->amount);
        $this->assertSame('active', $this->tenant->fresh()->status);
        $this->actingAs($this->student())->get('/dashboard')->assertOk();
    }

    public function test_a_suspension_for_misuse_is_not_lifted_by_paying(): void
    {
        $this->actingAs($this->platform)->put("/platform/institutions/{$this->tenant->id}", ['status' => 'suspended', 'suspension_reason' => 'abuse'])->assertRedirect();
        $sub = app(Billing::class)->start($this->tenant->fresh(), Plan::where('key', 'starter')->first(), 'yearly', null, 0);
        app(Billing::class)->pay(Invoice::where('subscription_id', $sub->id)->sole(), 'manual', 'M-1', $sub->price);

        $this->assertSame('suspended', $this->tenant->fresh()->status);
        $this->getJson('/api/exam/attempt/paper')->assertStatus(403); // the exam API stops too
    }

    public function test_the_landing_page_lists_public_plans_with_prices(): void
    {
        Plan::where('key', 'exams_only')->update(['is_public' => false]);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('welcome')->has('plans', 3)
            ->where('plans.0.key', 'starter')->where('plans.1.highlighted', true));
    }

    public function test_platform_administrators_never_appear_in_an_institutions_people(): void
    {
        $this->assertFalse(User::whereKey($this->platform->id)->exists());
        $this->actingAs($this->admin)->get('/admin/people')->assertOk()
            ->assertDontSee($this->platform->email);
    }
}
