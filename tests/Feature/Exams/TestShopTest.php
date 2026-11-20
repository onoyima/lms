<?php

namespace Tests\Feature\Exams;

use App\Models\ExamCandidate;
use App\Models\ShopOrder;
use App\Models\TestPackage;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Test-series shop: packages set by the institution, cart, vouchers, Paystack, receipts and timed access to the exams. */
class TestShopTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
    }

    private function package(array $exams, int $price = 500000): TestPackage
    {
        $this->actingAs($this->officer)->post('/admin/shop/packages', ['title' => 'JAMB mock series', 'price' => $price / 100, 'currency' => 'NGN', 'access_days' => 30,
            'active' => true, 'exam_ids' => array_map(fn ($e) => $e->id, $exams)])->assertSessionHasNoErrors();

        return TestPackage::latest('id')->first();
    }

    public function test_admin_sets_prices_currency_and_key_and_a_visitor_buys_with_paystack(): void
    {
        $exam = $this->cbtExam(['category' => 'mock']);
        $pkg = $this->package([$exam]);
        $this->assertSame(500000, $pkg->price);
        $this->put('/admin/shop/settings', ['currency' => 'NGN', 'paystack_secret' => 'sk_test_abc123', 'open' => true])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('sk_test_abc123', json_encode(tenant()->fresh()->settings)); // stored encrypted
        $this->get('/admin/shop')->assertInertia(fn ($p) => $p->where('settings.has_key', true)->where('packages.0.price', 500000)->missing('settings.paystack_secret'));
        auth()->logout();

        // a visitor browses, fills the cart and makes an account at checkout
        $this->get('/shop')->assertOk()->assertInertia(fn ($p) => $p->component('shop/index')->where('packages.0.title', 'JAMB mock series'));
        $this->post("/shop/cart/{$pkg->id}")->assertRedirect();
        $this->get('/shop/checkout')->assertInertia(fn ($p) => $p->where('price.total', 500000)->where('signedIn', false)->where('online', true));

        $state = 'abandoned';
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz']]),
            'api.paystack.co/transaction/verify/*' => function () use (&$state) {
                return Http::response(['status' => true, 'data' => ['status' => $state, 'amount' => 500000, 'currency' => 'NGN']]);
            }]);
        $this->post('/shop/checkout', ['name' => 'Chidi Obi', 'email' => 'chidi@example.com', 'password' => 'secret-pass-1', 'price' => 1])
            ->assertRedirect('https://checkout.paystack.com/xyz');
        $order = ShopOrder::sole();
        $this->assertSame(500000, $order->total); // the price comes from our records, not the browser
        $buyer = User::where('email', 'chidi@example.com')->sole();
        $this->assertAuthenticatedAs($buyer);
        Http::assertSent(fn ($r) => $r['amount'] === 500000 && $r['currency'] === 'NGN' && $r->hasHeader('Authorization', 'Bearer sk_test_abc123'));

        // returning with an unpaid reference gives nothing
        $this->get('/shop/paid?reference='.$order->reference)->assertRedirect("/shop/orders/{$order->number}");
        $this->assertSame('pending', $order->fresh()->status);

        // a signed webhook from Paystack, once confirmed, gives access to the exam
        $state = 'success';
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $order->reference]]);
        $this->call('POST', '/webhooks/shop/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'bad'], $body)->assertStatus(401);
        $this->call('POST', '/webhooks/shop/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_abc123')], $body)->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->receipt_code);
        $seat = ExamCandidate::where('user_id', $buyer->id)->sole();
        $this->assertSame('Test series', $seat->session->name);
        $this->assertTrue($seat->session->ends_at->greaterThan(now()->addDays(29)));

        // the same payment reported twice does not grant twice
        $this->call('POST', '/webhooks/shop/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_abc123')], $body)->assertOk();
        $this->assertSame(1, DB::table('package_access')->count());

        $this->actingAs($buyer)->get('/my/test-series')->assertInertia(fn ($p) => $p->where('packages.0.active', true)->where('packages.0.exams.0.candidate', $seat->id));
        $this->get("/shop/orders/{$order->number}/receipt.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $other = User::factory()->create(['user_type' => 'student']);
        $this->actingAs($other)->get("/shop/orders/{$order->number}/receipt.pdf")->assertNotFound();
    }

    public function test_vouchers_discount_or_make_a_package_free_and_are_used_up(): void
    {
        $pkg = $this->package([$this->cbtExam(['category' => 'practice'])], 200000);
        $this->post('/admin/shop/vouchers', ['code' => 'half', 'kind' => 'percent', 'value' => 50])->assertSessionHasNoErrors();
        $this->post('/admin/shop/vouchers', ['code' => 'GIFT', 'kind' => 'free', 'max_uses' => 1])->assertSessionHasNoErrors();
        $this->post('/admin/shop/vouchers', ['kind' => 'amount', 'value' => 100, 'count' => 5])->assertSessionHasNoErrors();
        $this->post('/admin/shop/vouchers', ['code' => 'BAD', 'kind' => 'percent', 'value' => 150])->assertSessionHasErrors('value');
        $this->assertSame(7, Voucher::count());
        $this->get('/admin/shop/vouchers.csv')->assertOk()->assertDownload('vouchers.csv');

        $buyer = User::factory()->create(['user_type' => 'student']);
        $this->actingAs($buyer)->post("/shop/cart/{$pkg->id}");
        $this->get('/shop/checkout?voucher=half')->assertInertia(fn ($p) => $p->where('price.discount', 100000)->where('price.total', 100000));
        $this->get('/shop/checkout?voucher=NOPE')->assertInertia(fn ($p) => $p->where('price.voucher_error', 'That voucher is not valid.')->where('price.total', 200000));

        // a free voucher completes the order with no payment
        $this->post('/shop/checkout', ['voucher' => 'gift'])->assertRedirect();
        $order = ShopOrder::sole();
        $this->assertSame(['paid', 'voucher', 0], [$order->status, $order->provider, (int) $order->total]);
        $this->assertSame(1, Voucher::where('code', 'GIFT')->value('used'));
        $this->assertTrue(ExamCandidate::where('user_id', $buyer->id)->exists());

        // used up
        $this->post("/shop/cart/{$pkg->id}");
        $this->get('/shop/checkout?voucher=GIFT')->assertInertia(fn ($p) => $p->where('price.voucher_error', 'That voucher is not valid.'));

        // buying again extends access instead of starting over
        app(\App\Services\Shop\TestShop::class)->grant($buyer, $pkg);
        $this->assertTrue(\Illuminate\Support\Carbon::parse(DB::table('package_access')->max('expires_at'))->greaterThan(now()->addDays(59)));
    }

    public function test_shop_can_be_closed_and_without_a_key_paid_orders_cannot_start(): void
    {
        $pkg = $this->package([$this->cbtExam()]);
        $buyer = User::factory()->create(['user_type' => 'student']);
        $this->actingAs($buyer)->post("/shop/cart/{$pkg->id}");
        $this->get('/shop/checkout')->assertInertia(fn ($p) => $p->where('online', false));
        $this->post('/shop/checkout')->assertSessionHas('error', 'Online payment is not set up for this shop yet.');
        $this->assertSame('pending', ShopOrder::sole()->status);
        $this->actingAs($buyer)->get('/admin/shop')->assertForbidden();

        $this->actingAs($this->officer)->put('/admin/shop/settings', ['currency' => 'GHS', 'open' => false])->assertSessionHasNoErrors();
        $this->get('/shop')->assertNotFound();
    }
}
