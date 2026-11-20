<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ShopOrder;
use App\Models\TestPackage;
use App\Models\Voucher;
use App\Services\Audit\AuditLogger;
use App\Services\Shop\TestShop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The institution runs its own shop: packages (exams, price, currency and access days are all set
 * here, never fixed in code), vouchers, orders, and its own Paystack key.
 */
class ShopAdminController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $settings = (array) data_get(tenant()?->settings, 'shop', []);

        return Inertia::render('shop/admin', [
            'packages' => TestPackage::with('exams:id,title')->withCount(['exams'])->orderBy('position')->orderBy('id')->get()
                ->map(fn ($p) => $p->only(['id', 'title', 'description', 'price', 'currency', 'access_days', 'active', 'position'])
                    + ['exam_ids' => $p->exams->pluck('id'), 'exam_titles' => $p->exams->pluck('title'),
                        'sold' => DB::table('shop_order_items as i')->join('shop_orders as o', 'o.id', '=', 'i.shop_order_id')->where('o.status', 'paid')->where('i.test_package_id', $p->id)->count()]),
            'vouchers' => Voucher::latest('id')->limit(200)->get(['id', 'code', 'kind', 'value', 'test_package_id', 'max_uses', 'used', 'expires_at', 'active']),
            'orders' => ShopOrder::with('user:id,name,email')->latest('id')->limit(100)->get()
                ->map(fn ($o) => $o->only(['id', 'number', 'status', 'subtotal', 'discount', 'total', 'currency', 'provider', 'paid_at', 'created_at']) + ['buyer' => $o->user?->only(['name', 'email'])]),
            'totals' => ShopOrder::where('status', 'paid')->groupBy('currency')->selectRaw('currency, COUNT(*) as orders, SUM(total) as total')->get(),
            'exams' => Exam::orderByDesc('id')->limit(500)->get(['id', 'title', 'category']),
            'settings' => ['currency' => $settings['currency'] ?? 'NGN', 'has_key' => ! empty($settings['paystack_secret']), 'open' => (bool) ($settings['open'] ?? true),
                'webhook' => url('/webhooks/shop/paystack'), 'link' => url('/shop')],
        ]);
    }

    private function packageData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:4000'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000000'], 'currency' => ['required', 'string', 'size:3', 'alpha'],
            'access_days' => ['required', 'integer', 'min:1', 'max:3650'], 'active' => ['boolean'], 'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'exam_ids' => ['array'], 'exam_ids.*' => ['integer', Rule::exists('exams', 'id')->where('tenant_id', tenant_id())],
        ]);
    }

    public function storePackage(Request $request): RedirectResponse
    {
        $d = $this->packageData($request);
        $p = TestPackage::create(['title' => $d['title'], 'description' => $d['description'] ?? null, 'price' => (int) round($d['price'] * 100), 'currency' => strtoupper($d['currency']),
            'access_days' => $d['access_days'], 'active' => $d['active'] ?? true, 'position' => $d['position'] ?? 0, 'created_by' => $request->user()->id]);
        $p->exams()->sync($d['exam_ids'] ?? []);
        $this->audit->record('shop.package_created', $p, ['price' => $p->price, 'currency' => $p->currency]);

        return back()->with('success', 'Package added.');
    }

    public function updatePackage(Request $request, TestPackage $package): RedirectResponse
    {
        $d = $this->packageData($request);
        $package->update(['title' => $d['title'], 'description' => $d['description'] ?? null, 'price' => (int) round($d['price'] * 100), 'currency' => strtoupper($d['currency']),
            'access_days' => $d['access_days'], 'active' => $d['active'] ?? $package->active, 'position' => $d['position'] ?? $package->position]);
        $package->exams()->sync($d['exam_ids'] ?? []);
        $this->audit->record('shop.package_updated', $package, ['price' => $package->price, 'currency' => $package->currency]);

        return back()->with('success', 'Package saved.');
    }

    public function storeVoucher(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'], 'kind' => ['required', Rule::in(['percent', 'amount', 'free'])],
            'value' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'test_package_id' => ['nullable', 'integer', Rule::exists('test_packages', 'id')->where('tenant_id', tenant_id())],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'expires_at' => ['nullable', 'date', 'after:now'], 'count' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        if ($d['kind'] === 'percent' && (($d['value'] ?? 0) < 1 || $d['value'] > 100)) {
            return back()->withErrors(['value' => 'A percentage is between 1 and 100.']);
        }
        $count = (int) ($d['count'] ?? 1);
        if (! empty($d['code']) && $count > 1) {
            return back()->withErrors(['count' => 'Leave the code empty to make several codes at once.']);
        }
        $value = match ($d['kind']) { 'percent' => (int) $d['value'], 'amount' => (int) round(($d['value'] ?? 0) * 100), default => 0 };
        $made = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper($d['code'] ?? '') ?: strtoupper(\Illuminate\Support\Str::random(8));
            if (Voucher::where('code', $code)->exists()) {
                if (! empty($d['code'])) {
                    return back()->withErrors(['code' => 'That code is already in use.']);
                }
                $i--;

                continue;
            }
            $made[] = Voucher::create(['code' => $code, 'kind' => $d['kind'], 'value' => $value, 'test_package_id' => $d['test_package_id'] ?? null,
                'max_uses' => $d['max_uses'] ?? null, 'expires_at' => $d['expires_at'] ?? null, 'active' => true])->code;
        }
        $this->audit->record('shop.vouchers_created', null, ['count' => count($made), 'kind' => $d['kind']]);

        return back()->with('success', count($made) === 1 ? "Voucher {$made[0]} made." : count($made).' vouchers made.');
    }

    public function toggleVoucher(Voucher $voucher): RedirectResponse
    {
        $voucher->update(['active' => ! $voucher->active]);

        return back();
    }

    public function vouchersCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Code', 'Kind', 'Value', 'Package', 'Max uses', 'Used', 'Expires', 'Active']);
            $titles = TestPackage::pluck('title', 'id');
            foreach (Voucher::orderBy('id')->cursor() as $v) {
                fputcsv($out, [$v->code, $v->kind, $v->kind === 'amount' ? number_format($v->value / 100, 2, '.', '') : $v->value, $titles[$v->test_package_id] ?? 'Any',
                    $v->max_uses ?? '', $v->used, $v->expires_at?->toDateString() ?? '', $v->active ? 'yes' : 'no']);
            }
            fclose($out);
        }, 'vouchers.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Re-check an order with Paystack (for a buyer who paid but closed the page before returning). */
    public function recheck(ShopOrder $order, TestShop $shop): RedirectResponse
    {
        return back()->with($shop->confirm($order) ? 'success' : 'error', $order->fresh()->status === 'paid' ? 'Paid: access given.' : 'Paystack has no completed payment for this order.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $d = $request->validate(['currency' => ['required', 'string', 'size:3', 'alpha'], 'paystack_secret' => ['nullable', 'string', 'max:200', 'regex:/^sk_(test|live)_[A-Za-z0-9]+$/'],
            'remove_key' => ['boolean'], 'open' => ['boolean']]);
        $tenant = tenant();
        $settings = (array) $tenant->settings;
        $shop = (array) ($settings['shop'] ?? []);
        $shop['currency'] = strtoupper($d['currency']);
        $shop['open'] = (bool) ($d['open'] ?? true);
        if (! empty($d['remove_key'])) {
            unset($shop['paystack_secret']);
        } elseif (! empty($d['paystack_secret'])) {
            $shop['paystack_secret'] = Crypt::encryptString($d['paystack_secret']);
        }
        $settings['shop'] = $shop;
        $tenant->forceFill(['settings' => $settings])->save();
        $this->audit->record('shop.settings_changed', null, ['currency' => $shop['currency'], 'key_changed' => ! empty($d['paystack_secret']) || ! empty($d['remove_key'])]);

        return back()->with('success', 'Shop settings saved.');
    }
}
