<?php

namespace App\Services\Shop;

use App\Models\ExamSession;
use App\Models\ShopOrder;
use App\Models\TestPackage;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\AcademicRecord;
use App\Services\Exams\CandidateRoster;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The institution's test-series shop. Prices and the total always come from our records, never from
 * the browser. Payment goes to the institution's own Paystack account; an order is paid only when
 * Paystack confirms it (verify call or a signed webhook). Paying gives timed access: the buyer is
 * added to every exam in the package, in an always-open practice sitting, until access ends.
 */
class TestShop
{
    public function __construct(private AcademicRecord $records, private CandidateRoster $roster, private AuditLogger $audit) {}

    public static function paystackKey(): ?string
    {
        $k = (string) data_get(tenant()?->settings, 'shop.paystack_secret', '');
        try {
            return $k !== '' ? Crypt::decryptString($k) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<int> $packageIds  @return array{items: list<array>, subtotal: int, discount: int, total: int, currency: string, voucher: ?Voucher, voucher_error: ?string} */
    public function price(array $packageIds, ?string $voucherCode = null): array
    {
        $packages = TestPackage::whereIn('id', $packageIds)->where('active', true)->orderBy('position')->get();
        if ($packages->isEmpty()) {
            throw new RuntimeException('Your cart is empty.');
        }
        if ($packages->pluck('currency')->unique()->count() > 1) {
            throw new RuntimeException('Packages in different currencies must be bought separately.');
        }
        $subtotal = (int) $packages->sum('price');
        $voucher = null;
        $error = null;
        $discount = 0;
        if ($voucherCode !== null && trim($voucherCode) !== '') {
            $voucher = Voucher::where('code', strtoupper(trim($voucherCode)))->first();
            if (! $voucher || ! $voucher->usable()) {
                [$voucher, $error] = [null, 'That voucher is not valid.'];
            } elseif ($voucher->test_package_id && ! $packages->contains('id', $voucher->test_package_id)) {
                [$voucher, $error] = [null, 'That voucher is for a package that is not in your cart.'];
            } else {
                $base = $voucher->test_package_id ? (int) $packages->firstWhere('id', $voucher->test_package_id)->price : $subtotal;
                $discount = match ($voucher->kind) {
                    'free' => $base,
                    'percent' => (int) round($base * min(100, $voucher->value) / 100),
                    default => min($base, (int) $voucher->value),
                };
            }
        }

        return ['items' => $packages->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'price' => (int) $p->price, 'access_days' => $p->access_days])->all(),
            'subtotal' => $subtotal, 'discount' => $discount, 'total' => max(0, $subtotal - $discount), 'currency' => $packages->first()->currency,
            'voucher' => $voucher, 'voucher_error' => $error];
    }

    public function createOrder(User $buyer, array $packageIds, ?string $voucherCode): ShopOrder
    {
        $p = $this->price($packageIds, $voucherCode);
        if ($p['voucher_error']) {
            throw new RuntimeException($p['voucher_error']);
        }

        return DB::transaction(function () use ($buyer, $p) {
            $order = ShopOrder::create([
                'user_id' => $buyer->id, 'number' => 'TS'.now()->format('ymd').'-'.strtoupper(Str::random(6)),
                'subtotal' => $p['subtotal'], 'discount' => $p['discount'], 'total' => $p['total'], 'currency' => $p['currency'],
                'status' => 'pending', 'voucher_id' => $p['voucher']?->id,
            ]);
            foreach ($p['items'] as $i) {
                $order->items()->create(['test_package_id' => $i['id'], 'title' => $i['title'], 'price' => $i['price']]);
            }

            return $order;
        });
    }

    /** Paystack checkout page for the order. @return string the address to send the buyer to */
    public function startPayment(ShopOrder $order, string $callbackUrl): string
    {
        $key = self::paystackKey() ?? throw new RuntimeException('Online payment is not set up for this shop yet.');
        $order->update(['reference' => 'TS'.$order->id.'-'.bin2hex(random_bytes(6)), 'provider' => 'paystack']);
        $res = Http::timeout(15)->withToken($key)->acceptJson()->post('https://api.paystack.co/transaction/initialize', [
            'email' => $order->user->email, 'amount' => $order->total, 'currency' => $order->currency, 'reference' => $order->reference,
            'callback_url' => $callbackUrl, 'metadata' => ['shop_order_id' => $order->id, 'tenant_id' => $order->tenant_id],
        ]);
        if (! $res->successful() || ! $res->json('status')) {
            throw new RuntimeException('The payment service did not accept the request. Try again.');
        }

        return (string) $res->json('data.authorization_url');
    }

    /** Asks Paystack whether the order is paid; marks it paid (once) if the amount and currency match. */
    public function confirm(ShopOrder $order): bool
    {
        if ($order->status === 'paid') {
            return true;
        }
        $key = self::paystackKey();
        if (! $key || ! $order->reference) {
            return false;
        }
        $res = Http::timeout(15)->withToken($key)->acceptJson()->get('https://api.paystack.co/transaction/verify/'.rawurlencode($order->reference));
        $d = (array) $res->json('data');
        $paid = $res->successful() && ($d['status'] ?? '') === 'success' && (int) ($d['amount'] ?? 0) === (int) $order->total && strtoupper((string) ($d['currency'] ?? '')) === $order->currency;
        if ($paid) {
            $this->complete($order, 'paystack');
        }

        return $paid;
    }

    public function validSignature(string $body, ?string $signature): bool
    {
        $key = self::paystackKey();

        return $key && $signature && hash_equals(hash_hmac('sha512', $body, $key), $signature);
    }

    /** Paid (or free with a voucher): receipt, access to every exam in each package, voucher used. */
    public function complete(ShopOrder $order, string $provider): void
    {
        DB::transaction(function () use ($order, $provider) {
            $locked = ShopOrder::whereKey($order->id)->lockForUpdate()->first();
            if ($locked->status === 'paid') {
                return;
            }
            $buyer = $locked->user;
            $receipt = $this->records->issue($buyer, 'receipt', 'Receipt '.$locked->number.' · '.$locked->currency.' '.number_format($locked->total / 100, 2),
                ['order' => $locked->number, 'total' => $locked->total, 'currency' => $locked->currency], null);
            $locked->update(['status' => 'paid', 'provider' => $provider, 'paid_at' => now(), 'receipt_code' => $receipt]);
            if ($locked->voucher_id) {
                Voucher::whereKey($locked->voucher_id)->increment('used');
            }
            foreach ($locked->items as $item) {
                $this->grant($buyer, TestPackage::findOrFail($item->test_package_id), $locked->id);
            }
            $this->audit->record('shop.order_paid', $locked, ['total' => $locked->total, 'provider' => $provider], $buyer->id);
        });
        $order->refresh();
    }

    /** Access to a package: extends any access already running. */
    public function grant(User $buyer, TestPackage $package, ?int $orderId = null): void
    {
        $current = DB::table('package_access')->where('user_id', $buyer->id)->where('test_package_id', $package->id)->where('expires_at', '>', now())->max('expires_at');
        $from = $current ? \Illuminate\Support\Facades\Date::parse($current) : now();
        $until = $from->copy()->addDays($package->access_days);
        DB::table('package_access')->insert(['tenant_id' => $package->tenant_id, 'user_id' => $buyer->id, 'test_package_id' => $package->id, 'shop_order_id' => $orderId,
            'starts_at' => now(), 'expires_at' => $until, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($package->exams as $exam) {
            $this->roster->add($this->openSitting($exam, $until), ['user_ids' => [$buyer->id], 'quiet' => true]);
        }
    }

    /** The always-open practice sitting buyers write in (one per exam, extended as access is sold). */
    private function openSitting(\App\Models\Exam $exam, \Carbon\CarbonInterface $until): ExamSession
    {
        $s = ExamSession::where('exam_id', $exam->id)->where('name', 'Test series')->first();
        if (! $s) {
            $s = $exam->sessions()->create(['name' => 'Test series', 'mode' => 'remote', 'starts_at' => now()->subMinute(), 'ends_at' => $until,
                'late_entry_minutes' => 0, 'status' => 'open', 'is_practice' => in_array($exam->category, ['practice', 'mock', 'quiz', 'test'], true)]);
        } elseif ($s->ends_at->lt($until)) {
            $s->update(['ends_at' => $until, 'status' => 'open']);
        }

        return $s;
    }
}
