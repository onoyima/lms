<?php

namespace App\Services\Billing;

use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Support\Platform\PlatformSettings;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Subscriptions, invoices and payments (the platform's business with each institution).
 *
 *  - Billing periods: monthly, quarterly, half-yearly, yearly. Prices come from the package.
 *  - Trial, grace period, when renewal invoices go out and reminder days come from the package or the
 *    platform settings (changed in the console, never in code).
 *  - Paying an invoice does what the invoice is for: renew (subscription), move to another package or
 *    billing period (change), or switch on extra capacity (addon).
 *  - Unpaid subscriptions become past due and, after the grace period, the institution is suspended until
 *    it pays — its data is never deleted. Every change is written to the subscription history.
 */
class Billing
{
    /** cycle => months */
    public const CYCLES = ['monthly' => 1, 'quarterly' => 3, 'biannual' => 6, 'yearly' => 12];

    public const CYCLE_LABELS = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'biannual' => 'Every 6 months', 'yearly' => 'Yearly'];

    public static function money(int $minor, string $currency = 'NGN'): string
    {
        return ($currency === 'NGN' ? '₦' : $currency.' ').number_format($minor / 100, 2);
    }

    public static function cycleRule(): string
    {
        return 'in:'.implode(',', array_keys(self::CYCLES));
    }

    public static function trialDays(?Plan $plan = null): int
    {
        return (int) ($plan?->trial_days ?? PlatformSettings::get('trial_days'));
    }

    public static function graceDays(?Plan $plan = null): int
    {
        return (int) ($plan?->grace_days ?? PlatformSettings::get('grace_days'));
    }

    public static function monthlyValue(Subscription $s): int
    {
        return intdiv((int) $s->price, self::CYCLES[$s->cycle] ?? 12);
    }

    public function start(Tenant $tenant, Plan $plan, string $cycle, ?int $price = null, ?int $trialDays = null, ?array $limits = null): Subscription
    {
        abort_unless(isset(self::CYCLES[$cycle]), 422, 'Unknown billing period.');
        $trialDays ??= self::trialDays($plan);

        return DB::transaction(function () use ($tenant, $plan, $cycle, $price, $trialDays, $limits) {
            $old = Subscription::where('tenant_id', $tenant->id)->whereIn('status', ['trialing', 'active', 'past_due'])->latest('id')->first();
            Subscription::where('tenant_id', $tenant->id)->whereIn('status', ['trialing', 'active', 'past_due'])->update(['status' => 'cancelled']);
            $now = CarbonImmutable::now();
            $sub = Subscription::create([
                'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'cycle' => $cycle, 'currency' => $plan->currency,
                'price' => $price ?? $plan->priceFor($cycle), 'student_limit' => $plan->student_limit, 'limits' => $limits ?? $old?->limits,
                'status' => $trialDays > 0 ? 'trialing' : 'active', 'trial_ends_at' => $trialDays > 0 ? $now->addDays($trialDays) : null,
                'current_period_start' => $now, 'current_period_end' => $trialDays > 0 ? $now->addDays($trialDays) : $this->periodEnd($now, $cycle),
            ]);
            $tenant->update(['plan_id' => $plan->id]);
            // open invoices of the replaced subscription no longer apply
            if ($old) {
                Invoice::where('subscription_id', $old->id)->where('status', 'open')->where('kind', 'subscription')->update(['status' => 'void']);
            }
            SubscriptionEvent::record($tenant->id, $old ? ($old->plan_id !== $plan->id ? 'plan_changed' : 'cycle_changed') : 'created',
                array_filter(['from_plan' => $old?->plan?->name, 'plan' => $plan->name, 'cycle' => $cycle, 'price' => $sub->price, 'trial_days' => $trialDays ?: null]), $sub->id);
            if ($trialDays <= 0 && $sub->price > 0) {
                $this->invoice($sub, $now, $this->periodEnd($now, $cycle), $now->addDays(7));
            }
            Entitlements::forget($tenant->id);

            return $sub;
        });
    }

    public function periodEnd(CarbonImmutable $start, string $cycle): CarbonImmutable
    {
        return $start->addMonthsNoOverflow(self::CYCLES[$cycle] ?? 12);
    }

    private function nextNumber(): string
    {
        $seq = Invoice::whereYear('created_at', now()->year)->count() + 1;
        while (Invoice::where('number', sprintf('VT-%d-%05d', now()->year, $seq))->exists()) {
            $seq++;
        }

        return sprintf('VT-%d-%05d', now()->year, $seq);
    }

    public function invoice(Subscription $sub, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $due): Invoice
    {
        $existing = Invoice::where('subscription_id', $sub->id)->where('kind', 'subscription')->where('period_start', $from)->where('status', '!=', 'void')->first();
        if ($existing) {
            return $existing;
        }
        $plan = $sub->plan;

        return Invoice::create([
            'tenant_id' => $sub->tenant_id, 'subscription_id' => $sub->id, 'number' => $this->nextNumber(), 'status' => 'open', 'kind' => 'subscription',
            'amount' => $sub->price, 'currency' => $sub->currency, 'period_start' => $from, 'period_end' => $to, 'due_at' => $due,
            'lines' => [['description' => "{$plan->name} package, ".mb_strtolower(self::CYCLE_LABELS[$sub->cycle] ?? $sub->cycle).' ('.$from->format('j M Y').' – '.$to->format('j M Y').')', 'amount' => $sub->price]],
        ]);
    }

    /**
     * An institution asks to move to another package or billing period. The unused part of a paid period is
     * credited; when nothing is owed the change happens at once, otherwise it happens when the invoice is paid.
     */
    public function changeInvoice(Tenant $tenant, Plan $plan, string $cycle): ?Invoice
    {
        abort_unless(isset(self::CYCLES[$cycle]), 422, 'Unknown billing period.');
        $sub = Entitlements::current($tenant->id);
        $now = CarbonImmutable::now();
        $price = $plan->priceFor($cycle);
        $credit = 0;
        if ($sub && $sub->status === 'active' && $sub->price > 0 && $sub->current_period_end?->isFuture()) {
            $total = max(1, $sub->current_period_start->diffInSeconds($sub->current_period_end));
            $left = $now->diffInSeconds($sub->current_period_end);
            $credit = (int) floor($sub->price * min(1, $left / $total));
        }
        $amount = max(0, $price - $credit);
        if ($amount === 0) {
            $this->start($tenant, $plan, $cycle, $price, 0);

            return null;
        }
        Invoice::where('tenant_id', $tenant->id)->where('kind', 'change')->where('status', 'open')->update(['status' => 'void']);
        $lines = [['description' => "{$plan->name} package, ".mb_strtolower(self::CYCLE_LABELS[$cycle]).' (from the day it is paid)', 'amount' => $price]];
        if ($credit > 0) {
            $lines[] = ['description' => 'Credit for the unused part of the current period', 'amount' => -$credit];
        }

        return Invoice::create([
            'tenant_id' => $tenant->id, 'subscription_id' => $sub?->id, 'number' => $this->nextNumber(), 'status' => 'open', 'kind' => 'change',
            'amount' => $amount, 'currency' => $plan->currency, 'period_start' => $now, 'period_end' => $this->periodEnd($now, $cycle), 'due_at' => $now->addDays(7),
            'lines' => $lines, 'meta' => ['plan_id' => $plan->id, 'cycle' => $cycle, 'price' => $price],
        ]);
    }

    /** Extra capacity without changing package, priced by the package's add-on price list. */
    public function addonInvoice(Tenant $tenant, string $key, int $blocks, ?User $by = null): Invoice
    {
        $sub = Entitlements::current($tenant->id);
        abort_unless($sub && in_array($sub->status, ['trialing', 'active', 'past_due'], true), 422, 'Your institution needs a running subscription first.');
        $price = (array) (($tenant->plan?->addon_prices ?? [])[$key] ?? []);
        abort_unless(! empty($price['per']) && isset($price['price']), 422, 'Extra capacity of this kind is not sold on your package. Contact the platform team.');
        $quantity = (int) $price['per'] * $blocks;
        $end = $sub->current_period_end ? CarbonImmutable::parse($sub->current_period_end) : CarbonImmutable::now()->addYear();
        $amount = (int) $price['price'] * $blocks;
        [$label, $unit] = Entitlements::LIMITS[$key];

        return DB::transaction(function () use ($tenant, $key, $quantity, $end, $amount, $label, $unit, $sub, $by, $blocks) {
            $now = CarbonImmutable::now();
            $invoice = Invoice::create([
                'tenant_id' => $tenant->id, 'subscription_id' => $sub->id, 'number' => $this->nextNumber(), 'status' => 'open', 'kind' => 'addon',
                'amount' => $amount, 'currency' => $sub->currency, 'period_start' => $now, 'period_end' => $end, 'due_at' => $now->addDays(7),
                'lines' => [['description' => 'Extra '.mb_strtolower($label).': '.number_format($quantity).($unit && ! in_array($unit, ['accounts', 'people'], true) ? " $unit" : '').' until '.$end->format('j M Y'), 'amount' => $amount]],
            ]);
            $addon = TenantAddon::create(['tenant_id' => $tenant->id, 'limit_key' => $key, 'quantity' => $quantity, 'status' => 'pending', 'ends_at' => $end,
                'invoice_id' => $invoice->id, 'note' => "$blocks block(s)", 'created_by' => $by?->id]);
            $invoice->update(['meta' => ['addon_id' => $addon->id]]);

            return $invoice;
        });
    }

    /** Apply a discount code to an open invoice (once per invoice). */
    public function applyCoupon(Invoice $invoice, string $code): Invoice
    {
        return DB::transaction(function () use ($invoice, $code) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless($invoice->status === 'open', 409, 'This invoice is not open.');
            abort_if($invoice->discount > 0, 422, 'A discount is already applied to this invoice.');
            $coupon = Coupon::lockForUpdate()->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($code))])->first();
            abort_unless($coupon, 422, 'That code is not valid.');
            $tenant = Tenant::findOrFail($invoice->tenant_id);
            $plan = isset($invoice->meta['plan_id']) ? Plan::find($invoice->meta['plan_id']) : Subscription::find($invoice->subscription_id)?->plan;
            $cycle = $invoice->meta['cycle'] ?? Subscription::find($invoice->subscription_id)?->cycle ?? 'yearly';
            abort_if($coupon->currency !== $invoice->currency && $coupon->kind === 'fixed', 422, 'This code is for a different currency.');
            if ($why = $coupon->problem($tenant, $plan, $cycle)) {
                abort(422, $why);
            }
            $off = $coupon->discountOn($invoice->amount);
            abort_if($off <= 0, 422, 'This code gives no discount on this invoice.');
            $lines = $invoice->lines;
            $lines[] = ['description' => "Discount ({$coupon->code})", 'amount' => -$off];
            $invoice->update(['amount' => $invoice->amount - $off, 'discount' => $off, 'lines' => $lines]);
            DB::table('coupon_redemptions')->insert(['coupon_id' => $coupon->id, 'tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'amount_off' => $off, 'created_at' => now()]);
            $coupon->increment('redeemed');
            SubscriptionEvent::record($tenant->id, 'coupon_applied', ['code' => $coupon->code, 'invoice' => $invoice->number, 'off' => $off], $invoice->subscription_id);

            return $invoice;
        });
    }

    /** Record a payment once (by reference) and do what the invoice is for. */
    public function pay(Invoice $invoice, string $provider, string $reference, int $amount, ?User $by = null, array $meta = [], ?string $note = null): Payment
    {
        $result = DB::transaction(function () use ($invoice, $provider, $reference, $amount, $by, $meta, $note) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $existing = Payment::where('reference', $reference)->first();
            if ($existing) {
                return [$existing, false]; // webhooks and callbacks can both arrive
            }
            abort_if($invoice->status === 'void', 409, 'This invoice was cancelled.');
            abort_if($amount < $invoice->amount, 422, 'The amount paid is less than the invoice.');
            $payment = Payment::create(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'provider' => $provider, 'reference' => $reference,
                'amount' => $amount, 'currency' => $invoice->currency, 'status' => 'succeeded', 'paid_at' => now(), 'recorded_by' => $by?->id, 'meta' => $meta, 'note' => $note]);
            $fresh = $invoice->status !== 'paid';
            if ($fresh) {
                $invoice->update(['status' => 'paid', 'paid_at' => now()]);
                $this->fulfil($invoice);
                $tenant = Tenant::find($invoice->tenant_id);
                if ($tenant && $tenant->status === 'suspended' && $tenant->suspension_reason === 'non_payment') {
                    $tenant->update(['status' => 'active', 'suspension_reason' => null]);
                    SubscriptionEvent::record($tenant->id, 'reactivated', ['invoice' => $invoice->number], $invoice->subscription_id);
                }
                SubscriptionEvent::record($invoice->tenant_id, 'payment_succeeded', ['invoice' => $invoice->number, 'amount' => $amount, 'provider' => $provider, 'reference' => $reference], $invoice->subscription_id);
                Entitlements::forget($invoice->tenant_id);
            }

            return [$payment, $fresh];
        });
        [$payment, $fresh] = $result;
        if ($fresh) {
            $this->tell(Tenant::find($invoice->tenant_id), 'Payment received — '.$invoice->number,
                'Thank you. '.self::money($payment->amount, $payment->currency).' was received ('.$payment->reference.'). Your receipt is on the Billing page.', 'billing');
        }

        return $payment;
    }

    private function fulfil(Invoice $invoice): void
    {
        $meta = (array) ($invoice->meta ?? []);
        if ($invoice->kind === 'change' && isset($meta['plan_id'])) {
            $tenant = Tenant::findOrFail($invoice->tenant_id);
            $sub = $this->start($tenant, Plan::findOrFail($meta['plan_id']), $meta['cycle'], (int) ($meta['price'] ?? 0) ?: null, 0);
            // the invoice itself is the payment for the new first period
            Invoice::where('subscription_id', $sub->id)->where('status', 'open')->where('kind', 'subscription')->update(['status' => 'void']);
            $sub->update(['current_period_start' => $invoice->period_start, 'current_period_end' => $invoice->period_end]);
            $invoice->update(['subscription_id' => $sub->id]);

            return;
        }
        if ($invoice->kind === 'addon' && isset($meta['addon_id'])) {
            $addon = TenantAddon::find($meta['addon_id']);
            $addon?->update(['status' => 'active', 'starts_at' => now()]);
            SubscriptionEvent::record($invoice->tenant_id, 'addon_added', ['limit' => $addon?->limit_key, 'quantity' => $addon?->quantity, 'until' => $addon?->ends_at?->toDateString()], $invoice->subscription_id);

            return;
        }
        $sub = Subscription::find($invoice->subscription_id);
        if ($sub) {
            $sub->update(['status' => 'active', 'trial_ends_at' => null,
                'current_period_start' => $invoice->period_start ?? now(), 'current_period_end' => $invoice->period_end ?? $this->periodEnd(CarbonImmutable::now(), $sub->cycle)]);
            SubscriptionEvent::record($sub->tenant_id, 'renewed', ['until' => $sub->current_period_end?->toDateString()], $sub->id);
        }
    }

    /** A payment attempt that the provider reported as not successful. */
    public function paymentFailed(Invoice $invoice, string $reason): void
    {
        SubscriptionEvent::record($invoice->tenant_id, 'payment_failed', ['invoice' => $invoice->number, 'reason' => mb_substr($reason, 0, 190)], $invoice->subscription_id);
        $this->tell(Tenant::find($invoice->tenant_id), 'Payment not completed — '.$invoice->number,
            'A payment for invoice '.$invoice->number.' did not go through ('.mb_substr($reason, 0, 120).'). Nothing was charged to the invoice; you can try again from the Billing page.', 'billing');
    }

    /** Platform team: give more time on the current period (goodwill, delayed payment, migration). */
    public function extend(Subscription $sub, int $days, ?string $reason = null): Subscription
    {
        $end = CarbonImmutable::parse($sub->current_period_end ?? now())->addDays($days);
        $sub->update(['current_period_end' => $end] + ($sub->status === 'trialing' ? ['trial_ends_at' => $end] : []) + ($sub->status === 'past_due' ? ['status' => 'active'] : []));
        // an open renewal invoice moves with the new end
        Invoice::where('subscription_id', $sub->id)->where('status', 'open')->where('kind', 'subscription')->get()
            ->each(fn ($i) => $i->update(['due_at' => CarbonImmutable::parse($i->due_at)->addDays($days)]));
        $tenant = $sub->tenant;
        if ($tenant && $tenant->status === 'suspended' && $tenant->suspension_reason === 'non_payment') {
            $tenant->update(['status' => 'active', 'suspension_reason' => null]);
        }
        SubscriptionEvent::record($sub->tenant_id, 'extended', ['days' => $days, 'until' => $end->toDateString(), 'reason' => $reason], $sub->id);
        Entitlements::forget($sub->tenant_id);

        return $sub;
    }

    public function cancel(Subscription $sub, bool $atPeriodEnd = true): void
    {
        $sub->update($atPeriodEnd ? ['cancel_at_period_end' => true] : ['status' => 'cancelled', 'cancel_at_period_end' => false]);
        Invoice::where('subscription_id', $sub->id)->where('status', 'open')->where('kind', 'subscription')->where('period_start', '>=', now())->update(['status' => 'void']);
        SubscriptionEvent::record($sub->tenant_id, 'cancelled', ['at_period_end' => $atPeriodEnd, 'ends' => $sub->current_period_end?->toDateString()], $sub->id);
    }

    public function resume(Subscription $sub): void
    {
        $sub->update(['cancel_at_period_end' => false]);
        SubscriptionEvent::record($sub->tenant_id, 'resumed', [], $sub->id);
    }

    /** Daily: renewal invoices, reminders, past-due marking, suspension after the grace period, expiries. */
    public function run(): array
    {
        $now = CarbonImmutable::now();
        $done = ['invoiced' => 0, 'reminded' => 0, 'past_due' => 0, 'suspended' => 0, 'expired' => 0, 'addons_ended' => 0, 'grants_ended' => 0];
        $before = (int) PlatformSettings::get('invoice_days_before');
        $reminderDays = array_map('intval', (array) PlatformSettings::get('reminder_days'));
        foreach (Subscription::with('plan', 'tenant')->whereIn('status', ['trialing', 'active', 'past_due'])->get() as $sub) {
            if (! $sub->tenant) {
                continue;
            }
            if ($sub->cancel_at_period_end && $sub->current_period_end->lt($now)) {
                $sub->update(['status' => 'expired']);
                SubscriptionEvent::record($sub->tenant_id, 'expired', ['reason' => 'cancelled'], $sub->id);
                Entitlements::forget($sub->tenant_id);
                $done['expired']++;

                continue;
            }
            // a free trial on a priced package: invoice at the end of the trial
            if ($sub->status === 'trialing' && $sub->price > 0 && $sub->trial_ends_at?->lt($now->addDays($before))
                && ! Invoice::where('subscription_id', $sub->id)->where('status', '!=', 'void')->exists()) {
                $from = CarbonImmutable::parse($sub->trial_ends_at);
                $inv = $this->invoice($sub, $from, $this->periodEnd($from, $sub->cycle), $from->isPast() ? $now->addDays(7) : $from);
                $this->tell($sub->tenant, 'Invoice '.$inv->number, 'Your free trial ends on '.$from->format('j M Y').'. To keep using '.$sub->plan->name.', pay '.self::money($inv->amount, $inv->currency).'.', 'billing');
                $done['invoiced']++;
            } elseif ($sub->status !== 'trialing' && $sub->price > 0 && $sub->current_period_end->lt($now->addDays($before)) && ! $sub->cancel_at_period_end) {
                // renewal invoice ahead of the period end
                $from = CarbonImmutable::parse($sub->current_period_end);
                if (! Invoice::where('subscription_id', $sub->id)->where('kind', 'subscription')->where('period_start', $from)->exists()) {
                    $inv = $this->invoice($sub, $from, $this->periodEnd($from, $sub->cycle), $from);
                    $this->tell($sub->tenant, 'Invoice '.$inv->number, 'Your '.$sub->plan->name.' subscription renews on '.$from->format('j M Y').'. Amount: '.self::money($inv->amount, $inv->currency).'.', 'billing');
                    $done['invoiced']++;
                }
            }
            // a trial on a free package (or with nothing to pay) simply becomes active; a priced trial without payment ends
            if ($sub->status === 'trialing' && $sub->trial_ends_at?->lt($now)) {
                if ($sub->price <= 0) {
                    $sub->update(['status' => 'active', 'trial_ends_at' => null, 'current_period_end' => $this->periodEnd($now, $sub->cycle)]);
                } else {
                    $sub->update(['status' => 'past_due']);
                    SubscriptionEvent::record($sub->tenant_id, 'trial_ended', [], $sub->id);
                }
            }
            $grace = self::graceDays($sub->plan);
            $unpaid = Invoice::where('subscription_id', $sub->id)->where('status', 'open')->where('due_at', '<', $now)->orderBy('due_at')->first();
            if ($unpaid && $sub->status === 'active') {
                $sub->update(['status' => 'past_due']);
                SubscriptionEvent::record($sub->tenant_id, 'past_due', ['invoice' => $unpaid->number], $sub->id);
                $this->tell($sub->tenant, 'Payment overdue', 'Invoice '.$unpaid->number.' is overdue. Access will be suspended on '.CarbonImmutable::parse($unpaid->due_at)->addDays($grace)->format('j M Y').' if it is not paid.', 'billing');
                $done['past_due']++;
            }
            if ($unpaid && CarbonImmutable::parse($unpaid->due_at)->addDays($grace)->lt($now) && $sub->tenant->status === 'active') {
                $sub->tenant->update(['status' => 'suspended', 'suspension_reason' => 'non_payment']);
                SubscriptionEvent::record($sub->tenant_id, 'suspended', ['reason' => 'non_payment', 'invoice' => $unpaid->number], $sub->id);
                $done['suspended']++;
            }
        }
        // reminders for open invoices on the configured days before they are due (once a day at most)
        foreach (Invoice::with('tenant')->where('status', 'open')->where('due_at', '>', $now)->get() as $inv) {
            $days = (int) $now->startOfDay()->diffInDays(CarbonImmutable::parse($inv->due_at)->startOfDay());
            if (in_array($days, $reminderDays, true) && (! $inv->reminded_at || $inv->reminded_at->lt($now->startOfDay()))) {
                $this->tell($inv->tenant, 'Reminder: invoice '.$inv->number.' is due in '.$days.' day'.($days === 1 ? '' : 's'),
                    'Amount: '.self::money($inv->amount, $inv->currency).', due '.CarbonImmutable::parse($inv->due_at)->format('j M Y').'.', 'billing');
                $inv->update(['reminded_at' => $now]);
                $done['reminded']++;
            }
        }
        // extra capacity and temporary module access that ran out
        foreach (TenantAddon::where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<=', $now)->get() as $a) {
            $a->update(['status' => 'ended']);
            SubscriptionEvent::record($a->tenant_id, 'addon_ended', ['limit' => $a->limit_key, 'quantity' => $a->quantity]);
            Entitlements::forget($a->tenant_id);
            $done['addons_ended']++;
        }
        foreach (\App\Models\TenantModule::whereNotNull('expires_at')->where('expires_at', '<=', $now)->get() as $m) {
            SubscriptionEvent::record($m->tenant_id, 'module_revoked', ['module' => $m->module, 'reason' => 'temporary access ended']);
            $m->delete(); // the package decides again; nothing else is touched
            Entitlements::forget($m->tenant_id);
            $done['grants_ended']++;
        }

        return $done;
    }

    /** Tell the institution's billing administrators (inside their institution). */
    public function tell(?Tenant $tenant, string $title, string $body, string $category = 'billing'): void
    {
        if (! $tenant) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () use ($title, $body, $category) {
            Notification::send(User::permission('billing.manage')->where('status', 'active')->get(), new LmsNotice($title, $body, '/institution/billing', true, $category));
        });
    }
}
