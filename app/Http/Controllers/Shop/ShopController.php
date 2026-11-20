<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Models\TestPackage;
use App\Models\User;
use App\Services\Shop\TestShop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The institution's test-series shop for candidates: browse packages, a cart kept in the session,
 * checkout (signing in, or creating an account), vouchers, payment by Paystack, receipt, and the
 * candidate's packages with their exams.
 */
class ShopController extends Controller
{
    public function __construct(private TestShop $shop) {}

    private function cart(Request $request): array
    {
        return array_values(array_unique(array_map('intval', (array) $request->session()->get('shop.cart', []))));
    }

    public static function open(): bool
    {
        return (bool) data_get(tenant()?->settings, 'shop.open', true);
    }

    public function index(Request $request): Response
    {
        abort_unless(self::open(), 404);

        return Inertia::render('shop/index', [
            'packages' => TestPackage::where('active', true)->withCount('exams')->orderBy('position')->orderBy('id')->get()
                ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'description' => $p->description, 'price' => (int) $p->price, 'currency' => $p->currency,
                    'access_days' => $p->access_days, 'exams' => $p->exams_count]),
            'cart' => $this->cart($request),
            'institution' => tenant()?->name,
            'signedIn' => (bool) $request->user(),
        ]);
    }

    public function add(Request $request, TestPackage $package): RedirectResponse
    {
        abort_unless($package->active && self::open(), 404);
        $request->session()->put('shop.cart', array_values(array_unique([...$this->cart($request), $package->id])));

        return back()->with('success', 'Added to your cart.');
    }

    public function remove(Request $request, TestPackage $package): RedirectResponse
    {
        $request->session()->put('shop.cart', array_values(array_diff($this->cart($request), [$package->id])));

        return back();
    }

    public function checkout(Request $request): Response|RedirectResponse
    {
        $cart = $this->cart($request);
        if (! $cart) {
            return redirect()->route('shop.index');
        }
        try {
            $price = $this->shop->price($cart, $request->query('voucher'));
        } catch (\RuntimeException $e) {
            return redirect()->route('shop.index')->with('error', $e->getMessage());
        }

        return Inertia::render('shop/checkout', [
            'price' => array_diff_key($price, ['voucher' => 1]) + ['voucher_code' => $price['voucher']?->code],
            'signedIn' => (bool) $request->user(),
            'online' => (bool) TestShop::paystackKey(),
        ]);
    }

    /** Place the order: sign in or create an account first; free orders complete at once, others go to Paystack. */
    public function pay(Request $request): RedirectResponse
    {
        abort_unless(self::open(), 404);
        $cart = $this->cart($request);
        if (! $cart) {
            return redirect()->route('shop.index');
        }
        $data = $request->validate(['voucher' => ['nullable', 'string', 'max:40']]);
        $buyer = $request->user();
        if (! $buyer) {
            $acc = $request->validate([
                'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:190'],
                'phone' => ['nullable', 'string', 'max:32'], 'password' => ['required', 'string', 'min:8', 'max:100'],
            ]);
            $existing = User::where('email', mb_strtolower($acc['email']))->first();
            if ($existing) {
                return back()->withErrors(['email' => 'There is already an account with this email: sign in first.']);
            }
            $buyer = User::create(['name' => $acc['name'], 'email' => mb_strtolower($acc['email']), 'phone' => $acc['phone'] ?? null,
                'password' => Hash::make($acc['password']), 'user_type' => 'student', 'status' => 'active']);
            \App\Support\Roles::ensureLearner($buyer);
            Auth::login($buyer);
            $request->session()->regenerate();
            $request->session()->put('shop.cart', $cart);
        }
        try {
            $order = $this->shop->createOrder($buyer, $cart, $data['voucher'] ?? null);
            if ($order->total === 0) {
                $this->shop->complete($order, $order->voucher_id ? 'voucher' : 'free');
                $request->session()->forget('shop.cart');

                return redirect()->route('shop.order', $order->number);
            }
            $url = $this->shop->startPayment($order, route('shop.callback'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $request->session()->forget('shop.cart');

        return redirect()->away($url);
    }

    /** Paystack sends the buyer back here; the order counts as paid only if Paystack confirms it. */
    public function callback(Request $request): RedirectResponse
    {
        $order = ShopOrder::where('reference', (string) $request->query('reference'))->where('user_id', $request->user()->id)->firstOrFail();
        $this->shop->confirm($order);

        return redirect()->route('shop.order', $order->number);
    }

    /** Paystack's server tells us about payments (signed with the institution's secret key). */
    public function webhook(Request $request): \Illuminate\Http\Response
    {
        abort_unless($this->shop->validSignature($request->getContent(), $request->header('X-Paystack-Signature')), 401);
        if ($request->input('event') === 'charge.success' && ($order = ShopOrder::where('reference', (string) $request->input('data.reference'))->first())) {
            $this->shop->confirm($order);
        }

        return response('ok');
    }

    public function order(Request $request, string $number): Response
    {
        $order = ShopOrder::with('items')->where('number', $number)->where('user_id', $request->user()->id)->firstOrFail();

        return Inertia::render('shop/order', ['order' => $order->only(['number', 'status', 'subtotal', 'discount', 'total', 'currency', 'provider', 'receipt_code', 'paid_at', 'created_at'])
            + ['items' => $order->items->map->only(['title', 'price'])]]);
    }

    public function receipt(Request $request, string $number, \App\Services\Reports\Exporter $exporter)
    {
        $order = ShopOrder::with('items', 'user')->where('number', $number)->where('status', 'paid')->firstOrFail();
        abort_unless($order->user_id === $request->user()->id || $request->user()->can('exams.manage'), 404);

        return $exporter->pdf('receipt-'.$order->number, 'pdf.receipt', ['title' => 'Receipt '.$order->number, 'order' => $order, 'tenant' => tenant(),
            'footer' => 'Check this receipt at '.url('/verify/'.$order->receipt_code)]);
    }

    /** The candidate's packages, with their exams and how long access lasts. */
    public function mine(Request $request): Response
    {
        $access = DB::table('package_access as a')->join('test_packages as p', 'p.id', '=', 'a.test_package_id')->where('a.user_id', $request->user()->id)
            ->groupBy('p.id', 'p.title')->selectRaw('p.id, p.title, MAX(a.expires_at) as expires_at')->get();
        $exams = DB::table('test_package_exams as pe')->join('exams as e', 'e.id', '=', 'pe.exam_id')->whereIn('pe.test_package_id', $access->pluck('id'))
            ->get(['pe.test_package_id', 'e.id', 'e.title', 'e.duration_minutes'])->groupBy('test_package_id');
        $seats = DB::table('exam_candidates as c')->join('exam_sessions as s', 's.id', '=', 'c.exam_session_id')->where('c.user_id', $request->user()->id)
            ->where('s.name', 'Test series')->pluck('c.id', 's.exam_id');

        return Inertia::render('shop/mine', [
            'packages' => $access->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'expires_at' => $a->expires_at, 'active' => now()->lt($a->expires_at),
                'exams' => ($exams[$a->id] ?? collect())->map(fn ($e) => ['title' => $e->title, 'minutes' => $e->duration_minutes, 'candidate' => $seats[$e->id] ?? null])->values()]),
            'orders' => ShopOrder::where('user_id', $request->user()->id)->latest('id')->limit(30)->get(['number', 'status', 'total', 'currency', 'created_at', 'receipt_code']),
        ]);
    }
}
