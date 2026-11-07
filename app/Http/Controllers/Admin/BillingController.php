<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingDocuments;
use App\Services\Billing\Entitlements;
use App\Models\SubscriptionEvent;
use App\Models\TenantAddon;
use App\Services\Billing\Paystack;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * An institution's own subscription: what it pays for, its invoices and its payments.
 * Institution administrators see only their own institution's billing; amounts always
 * come from the invoice on our side, never from the browser.
 */
class BillingController extends Controller
{
    public function __construct(private Billing $billing, private Paystack $paystack, private TenantContext $context, private AuditLogger $audit) {}

    private function ownInvoice(int $id): Invoice
    {
        $invoice = Invoice::findOrFail($id);
        abort_unless($invoice->tenant_id === $this->context->id(), 404);

        return $invoice;
    }

    public function index(Entitlements $entitlements): Response
    {
        $tenant = $this->context->get();
        $sub = Subscription::with('plan')->where('tenant_id', $tenant->id)->whereIn('status', ['trialing', 'active', 'past_due'])->latest('id')->first();
        $plan = $tenant->plan;

        return Inertia::render('admin/billing', [
            'institution' => ['name' => $tenant->name, 'status' => $tenant->status, 'reason' => $tenant->suspension_reason],
            'subscription' => $sub ? [
                'plan' => $sub->plan?->name, 'plan_id' => $sub->plan_id, 'plan_key' => $sub->plan?->key, 'status' => $sub->status, 'cycle' => $sub->cycle,
                'cycle_label' => Billing::CYCLE_LABELS[$sub->cycle] ?? $sub->cycle, 'price' => Billing::money($sub->price, $sub->currency),
                'period_end' => $sub->current_period_end, 'trial_ends_at' => $sub->trial_ends_at, 'cancel_at_period_end' => $sub->cancel_at_period_end,
            ] : null,
            'usage' => array_values(array_map(fn ($k, $r) => ['key' => $k] + $r, array_keys($r = $entitlements->report($tenant)), $r)),
            'addons' => collect((array) ($plan?->addon_prices ?? []))->filter(fn ($p, $k) => isset(Entitlements::LIMITS[$k]) && ! empty($p['per']))
                ->map(fn ($p, $k) => ['key' => $k, 'label' => Entitlements::LIMITS[$k][0], 'unit' => Entitlements::LIMITS[$k][1], 'per' => (int) $p['per'], 'price' => Billing::money((int) $p['price'], $plan->currency)])->values(),
            'activeAddons' => TenantAddon::where('tenant_id', $tenant->id)->whereIn('status', ['active', 'pending'])->latest('id')->get()
                ->map(fn ($a) => ['id' => $a->id, 'label' => Entitlements::LIMITS[$a->limit_key][0] ?? $a->limit_key, 'quantity' => $a->quantity, 'status' => $a->status, 'ends_at' => $a->ends_at]),
            'history' => SubscriptionEvent::where('tenant_id', $tenant->id)->latest('id')->limit(50)->get()->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'data' => $e->data, 'at' => $e->created_at]),
            'invoices' => Invoice::where('tenant_id', $tenant->id)->latest('id')->limit(100)->get()->map(fn ($i) => $i->only(['id', 'number', 'status', 'kind', 'due_at', 'paid_at', 'period_start', 'period_end', 'lines'])
                + ['amount' => Billing::money($i->amount, $i->currency), 'discount' => $i->discount > 0, 'overdue' => $i->status === 'open' && $i->due_at->isPast()]),
            'payments' => Payment::where('tenant_id', $tenant->id)->latest('id')->limit(100)->get()->map(fn ($p) => $p->only(['id', 'invoice_id', 'provider', 'reference', 'status', 'paid_at'])
                + ['amount' => Billing::money($p->amount, $p->currency)]),
            'plans' => Plan::where('is_public', true)->orderBy('sort')->get()->map(fn ($p) => ['id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'tagline' => $p->tagline,
                'features' => $p->features, 'prices' => collect(Billing::CYCLES)->map(fn ($m, $c) => Billing::money($p->priceFor($c), $p->currency))]),
            'cycles' => Billing::CYCLE_LABELS,
            'online' => $this->paystack->enabled(),
            'bank' => (string) config('platform.bank_transfer'),
        ]);
    }

    /** The institution's own invoice or receipt as a branded PDF. */
    public function document(int $invoice, BillingDocuments $docs): HttpResponse
    {
        $inv = $this->ownInvoice($invoice);
        $this->audit->record('billing.document_downloaded', $inv, ['number' => $inv->number]);

        return $docs->download($inv);
    }

    public function coupon(Request $request, int $invoice): RedirectResponse
    {
        $inv = $this->ownInvoice($invoice);
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $inv = $this->billing->applyCoupon($inv, $data['code']);
        $this->audit->record('billing.coupon_applied', $inv, ['code' => mb_strtoupper($data['code'])]);

        return back()->with('success', 'Discount applied. New amount: '.Billing::money($inv->amount, $inv->currency).'.');
    }

    /** Move to another package or billing period: an invoice (with credit for unused time), applied once paid. */
    public function change(Request $request): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'integer'], 'cycle' => ['required', Billing::cycleRule()]]);
        $plan = Plan::where('is_public', true)->findOrFail($data['plan_id']);
        $tenant = $this->context->get();
        $inv = $this->billing->changeInvoice($tenant, $plan, $data['cycle']);
        $this->audit->record('billing.change_requested', $tenant, ['plan' => $plan->key, 'cycle' => $data['cycle']]);

        return back()->with('success', $inv ? "Invoice {$inv->number} created. The change happens as soon as it is paid." : "Your institution is now on {$plan->name}.");
    }

    /** Buy extra capacity without changing package. */
    public function addon(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(Entitlements::LIMITS))], 'blocks' => ['required', 'integer', 'min:1', 'max:100']]);
        $inv = $this->billing->addonInvoice($this->context->get(), $data['key'], (int) $data['blocks'], $request->user());
        $this->audit->record('billing.addon_requested', $inv, ['key' => $data['key'], 'blocks' => $data['blocks']]);

        return back()->with('success', "Invoice {$inv->number} created. The extra capacity is switched on as soon as it is paid.");
    }

    public function cancel(Request $request): RedirectResponse
    {
        $sub = Subscription::where('tenant_id', $this->context->id())->whereIn('status', ['trialing', 'active', 'past_due'])->latest('id')->firstOrFail();
        $resume = $request->boolean('resume');
        $resume ? $this->billing->resume($sub) : $this->billing->cancel($sub, true);
        $this->audit->record($resume ? 'billing.resumed' : 'billing.cancelled', $sub, []);

        return back()->with('success', $resume ? 'The subscription will renew as normal.' : 'The subscription will end on '.$sub->current_period_end?->format('j M Y').'. You can undo this until then.');
    }

    /** Start a card / bank / USSD payment for one of our own open invoices. */
    public function pay(Request $request, int $invoice): HttpResponse
    {
        $inv = $this->ownInvoice($invoice);
        abort_unless($inv->status === 'open', 409, 'This invoice is not open for payment.');
        abort_unless($this->paystack->enabled(), 422, 'Online payment is not set up. Pay by bank transfer and send the reference to the platform team.');
        try {
            $r = $this->paystack->initialize($inv, $request->user()->email, route('billing.callback'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->audit->record('billing.payment_started', $inv, ['reference' => $r['reference']]);

        return Inertia::location($r['url']);
    }

    /** Paystack sends the payer back here; we confirm with Paystack before counting anything. */
    public function callback(Request $request): RedirectResponse
    {
        $ref = (string) $request->query('reference', $request->query('trxref', ''));
        $v = $this->paystack->verify($ref);
        if (! $v['paid'] || ! $v['invoice_id']) {
            if ($v['invoice_id'] && ($failed = Invoice::find($v['invoice_id'])) && $failed->tenant_id === $this->context->id() && $failed->status === 'open') {
                $this->billing->paymentFailed($failed, (string) ($v['data']['gateway_response'] ?? 'not completed'));
            }

            return redirect()->route('billing.index')->with('error', 'The payment was not completed. Nothing was charged to this invoice.');
        }
        $inv = $this->ownInvoice($v['invoice_id']);
        abort_unless($v['currency'] === $inv->currency && str_starts_with($ref, 'VT'.$inv->id.'-'), 422, 'The payment does not match this invoice.');
        $this->billing->pay($inv, 'paystack', $ref, $v['amount'], $request->user(), $v['data']);

        return redirect()->route('billing.index')->with('success', "Payment received for {$inv->number}. Thank you.");
    }

    /** Server-to-server notice from Paystack, signed with our secret key (HMAC-SHA512). */
    public function webhook(Request $request): JsonResponse
    {
        if (! $this->paystack->validSignature($request->getContent(), $request->header('X-Paystack-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $event = (string) $request->json('event');
        $ref = (string) $request->json('data.reference');
        if ($event !== 'charge.success' || ! preg_match('/^VT\d+-[a-f0-9]{12}$/', $ref)) {
            return response()->json(['ok' => true]); // other events are acknowledged and ignored
        }
        // ask Paystack again rather than trusting the body alone
        $v = $this->paystack->verify($ref);
        $inv = $v['invoice_id'] ? Invoice::find($v['invoice_id']) : null;
        if (! $v['paid'] || ! $inv || $v['currency'] !== $inv->currency || ! str_starts_with($ref, 'VT'.$inv->id.'-')) {
            Log::warning('Paystack webhook did not match an invoice', ['reference' => $ref]);

            return response()->json(['ok' => true]);
        }
        try {
            $this->billing->pay($inv, 'paystack', $ref, $v['amount'], null, $v['data']);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            Log::warning('Paystack payment not applied', ['reference' => $ref, 'reason' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }
}
