<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\InstitutionApplication;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingDocuments;
use App\Services\Billing\Entitlements;
use App\Models\Coupon;
use App\Models\SubscriptionEvent;
use App\Models\TenantAddon;
use App\Support\Platform\PlatformSettings;
use App\Services\Modules\ModuleRegistry;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform console: institutions, applications, plans, subscriptions, invoices and payments.
 * Runs with no institution in context, so institution records (people, exams, results…) cannot
 * load here. Only counts are read, for billing by size.
 */
class PlatformController extends Controller
{
    public function __construct(private Billing $billing, private AuditLogger $audit, private ModuleRegistry $modules) {}

    /** Aggregate usage only — never names or records. */
    private function usage(array $tenantIds): array
    {
        $count = fn ($q) => $q->whereIn('tenant_id', $tenantIds)->groupBy('tenant_id')->selectRaw('tenant_id, COUNT(*) n')->pluck('n', 'tenant_id');
        $students = $count(DB::table('users')->where('user_type', 'student')->where('status', 'active'));
        $staff = $count(DB::table('users')->where('user_type', 'staff')->where('status', 'active'));
        $attempts = $count(DB::table('attempts')->where('created_at', '>', now()->subDays(30)));

        return collect($tenantIds)->mapWithKeys(fn ($id) => [$id => ['students' => (int) ($students[$id] ?? 0), 'staff' => (int) ($staff[$id] ?? 0), 'attempts_30d' => (int) ($attempts[$id] ?? 0)]])->all();
    }

    private function subscriptionOf(int $tenantId): ?Subscription
    {
        return Subscription::with('plan')->where('tenant_id', $tenantId)->whereIn('status', ['trialing', 'active', 'past_due'])->latest('id')->first();
    }

    public function overview(): Response
    {
        $active = Subscription::whereIn('status', ['trialing', 'active', 'past_due'])->get();
        $mrr = $active->where('status', '!=', 'trialing')->sum(fn ($s) => Billing::monthlyValue($s));
        $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $paid = Payment::where('status', 'succeeded')->where('paid_at', '>=', $months->first())->get(['amount', 'paid_at']);
        $revenue = $months->map(fn ($m) => ['month' => $m->format('M y'), 'amount' => round($paid->filter(fn ($p) => $p->paid_at->isSameMonth($m))->sum('amount') / 100, 2)])->values();
        $since = now()->subDays(30);
        $events = SubscriptionEvent::where('created_at', '>=', $since)->selectRaw('type, COUNT(*) n')->groupBy('type')->pluck('n', 'type');

        return Inertia::render('platform/overview', [
            'stats' => [
                'institutions' => Tenant::count(), 'active' => Tenant::where('status', 'active')->count(), 'suspended' => Tenant::where('status', 'suspended')->count(),
                'trialing' => $active->where('status', 'trialing')->count(), 'past_due' => $active->where('status', 'past_due')->count(),
                'mrr' => Billing::money($mrr), 'collected_30d' => Billing::money((int) Payment::where('status', 'succeeded')->where('paid_at', '>', now()->subDays(30))->sum('amount')),
                'open_invoices' => Invoice::where('status', 'open')->count(), 'applications' => InstitutionApplication::where('status', 'pending')->count(),
                'students' => DB::table('users')->where('user_type', 'student')->where('status', 'active')->count(),
                'users' => DB::table('users')->whereNotNull('tenant_id')->count(), 'active_users' => DB::table('users')->whereNotNull('tenant_id')->where('status', 'active')->count(),
                'outstanding' => Billing::money((int) Invoice::where('status', 'open')->sum('amount')),
                'renewed_30d' => (int) ($events['renewed'] ?? 0), 'failed_30d' => (int) ($events['payment_failed'] ?? 0), 'expired_30d' => (int) ($events['expired'] ?? 0) + (int) ($events['suspended'] ?? 0),
                'storage_gb' => round((DB::table('course_items')->sum('file_size') + DB::table('assignment_submissions')->sum('file_size') + DB::table('media_recordings')->sum('size') + DB::table('evidence_files')->sum('size')) / 1073741824, 2),
            ],
            'revenue' => $revenue,
            'security' => [
                'incidents_7d' => \App\Models\SecurityEvent::withoutGlobalScopes()->whereIn('type', ['forbidden', 'login_locked', 'upload_blocked', 'rate_limited'])->where('created_at', '>', now()->subDays(7))->count(),
                'failed_logins_24h' => \App\Models\SecurityEvent::withoutGlobalScopes()->where('type', 'login_failed')->where('created_at', '>', now()->subDay())->count(),
                'api_calls_month' => Tenant::pluck('id')->sum(fn ($id) => (int) \Illuminate\Support\Facades\Cache::get(Entitlements::apiKey($id), 0)),
                'support_sessions_30d' => \App\Models\SupportSession::where('created_at', '>', now()->subDays(30))->count(),
                'maintenance' => (bool) PlatformSettings::get('maintenance_mode'),
            ],
            'moduleUsage' => (function () {
                $counts = [];
                foreach (Tenant::with('plan', 'moduleOverrides')->where('status', 'active')->get() as $t) {
                    foreach ($this->modules->enabledFor($t) as $m) {
                        $counts[$m] = ($counts[$m] ?? 0) + 1;
                    }
                }
                arsort($counts);

                return collect($counts)->map(fn ($n, $k) => ['module' => $this->modules->all()[$k]['name'] ?? $k, 'institutions' => $n])->values();
            })(),
            'byPlan' => Plan::orderBy('sort')->get(['id', 'name'])->map(fn ($p) => ['plan' => $p->name, 'institutions' => $active->where('plan_id', $p->id)->count()]),
            'recentPayments' => Payment::latest('id')->limit(8)->get()->map(fn ($p) => ['institution' => Tenant::find($p->tenant_id)?->name, 'amount' => Billing::money($p->amount, $p->currency), 'provider' => $p->provider, 'status' => $p->status, 'at' => $p->paid_at ?? $p->created_at]),
            'expiring' => Subscription::with('tenant:id,name', 'plan:id,name')->whereIn('status', ['trialing', 'active', 'past_due'])->where('current_period_end', '<', now()->addDays(30))
                ->orderBy('current_period_end')->limit(10)->get()->map(fn ($s) => ['institution' => $s->tenant?->name, 'tenant_id' => $s->tenant_id, 'plan' => $s->plan?->name, 'status' => $s->status, 'ends' => $s->current_period_end]),
        ]);
    }

    public function institutions(Request $request): Response
    {
        $q = trim((string) $request->query('q'));
        $tenants = Tenant::with('plan:id,name')->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%$q%")->orWhere('slug', 'like', "%$q%")))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))->orderBy('name')->get();
        $usage = $this->usage($tenants->pluck('id')->all());

        return Inertia::render('platform/institutions', [
            'institutions' => $tenants->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status, 'reason' => $t->suspension_reason,
                'plan' => $t->plan?->name, 'subscription' => ($s = $this->subscriptionOf($t->id)) ? ['status' => $s->status, 'ends' => $s->current_period_end] : null,
                'usage' => $usage[$t->id], 'domains' => $t->domains ?? []]),
            'plans' => Plan::orderBy('sort')->get(['id', 'name', 'key']),
            'filters' => $request->only('q', 'status'),
        ]);
    }

    public function institution(Tenant $tenant): Response
    {
        $sub = $this->subscriptionOf($tenant->id);

        return Inertia::render('platform/institution', [
            'institution' => $tenant->only(['id', 'name', 'short_name', 'slug', 'status', 'suspension_reason', 'domains', 'contact_name', 'contact_email', 'contact_phone', 'brand_color', 'created_at', 'approved_at', 'plan_id']),
            'usage' => $this->usage([$tenant->id])[$tenant->id],
            'subscription' => $sub ? $sub->only(['id', 'status', 'cycle', 'price', 'currency', 'student_limit', 'current_period_start', 'current_period_end', 'trial_ends_at', 'cancel_at_period_end']) + ['plan' => $sub->plan?->name, 'price_label' => Billing::money($sub->price, $sub->currency)] : null,
            'invoices' => Invoice::where('tenant_id', $tenant->id)->latest('id')->get()->map(fn ($i) => $i->only(['id', 'number', 'status', 'kind', 'amount', 'currency', 'due_at', 'paid_at', 'period_start', 'period_end']) + ['amount_label' => Billing::money($i->amount, $i->currency)]),
            'payments' => Payment::where('tenant_id', $tenant->id)->latest('id')->get()->map(fn ($p) => $p->only(['id', 'provider', 'reference', 'status', 'paid_at', 'note']) + ['amount_label' => Billing::money($p->amount, $p->currency)]),
            'plans' => Plan::orderBy('sort')->get(['id', 'name', 'key', 'price_monthly', 'price_quarterly', 'price_biannual', 'price_yearly', 'currency']),
            'modules' => collect($this->modules->all())->map(fn ($m, $key) => ['key' => $key, 'name' => $m['name'], 'always_on' => (bool) ($m['always_on'] ?? false)])->values(),
            'enabled' => $this->modules->enabledFor($tenant->load('plan', 'moduleOverrides')),
            'overrides' => $tenant->moduleOverrides->mapWithKeys(fn ($o) => [$o->module => $o->enabled]),
            'grants' => $tenant->moduleOverrides->filter(fn ($o) => $o->expires_at)->map(fn ($o) => ['module' => $o->module, 'expires_at' => $o->expires_at, 'reason' => $o->reason])->values(),
            'entitlements' => array_values(array_map(fn ($k, $r) => ['key' => $k] + $r, array_keys($r = app(Entitlements::class)->report($tenant)), $r)),
            'planLimits' => (array) ($tenant->plan?->limits ?? []),
            'customLimits' => $sub?->limits,
            'note' => $sub?->note,
            'addons' => TenantAddon::where('tenant_id', $tenant->id)->latest('id')->get()->map(fn ($a) => $a->only(['id', 'limit_key', 'quantity', 'status', 'starts_at', 'ends_at', 'note', 'invoice_id']) + ['label' => Entitlements::LIMITS[$a->limit_key][0] ?? $a->limit_key]),
            'history' => SubscriptionEvent::with('user:id,name')->where('tenant_id', $tenant->id)->latest('id')->limit(100)->get()->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'data' => $e->data, 'by' => $e->user?->name, 'at' => $e->created_at]),
            'limitKeys' => collect(Entitlements::LIMITS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l[0], 'unit' => $l[1]])->values(),
            'cycles' => Billing::CYCLE_LABELS,
            'supportSessions' => \App\Models\SupportSession::where('tenant_id', $tenant->id)->latest('id')->limit(20)->get()
                ->map(fn ($s) => $s->only(['id', 'reason', 'started_at', 'expires_at', 'ended_at', 'created_at']) + ['by' => User::find($s->platform_user_id)?->name]),
        ]);
    }

    /** Add an institution directly (e.g. after a contract), with its first administrator. */
    public function storeInstitution(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'], 'slug' => ['required', 'alpha_dash', 'max:40', 'unique:tenants,slug'],
            'domains' => ['array', 'max:10'], 'domains.*' => ['string', 'max:190', 'regex:/^[a-z0-9.-]+$/i'],
            'plan_id' => ['required', 'exists:plans,id'], 'cycle' => ['required', Billing::cycleRule()], 'trial_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'admin_name' => ['required', 'string', 'max:190'], 'admin_email' => ['required', 'email', 'max:190'], 'contact_phone' => ['nullable', 'string', 'max:40'],
        ]);
        $tenant = $this->createInstitution($data, $provisioner, $request->user());

        return redirect()->route('platform.institution', $tenant)->with('success', "{$tenant->name} created. A set-up email has gone to {$data['admin_email']}.");
    }

    private function createInstitution(array $d, TenantProvisioner $provisioner, User $by): Tenant
    {
        $plan = Plan::findOrFail($d['plan_id']);
        $tenant = $provisioner->provision(['slug' => strtolower($d['slug']), 'name' => $d['name'], 'domains' => array_values(array_filter($d['domains'] ?? [])),
            'contact_name' => $d['admin_name'], 'contact_email' => strtolower($d['admin_email']), 'contact_phone' => $d['contact_phone'] ?? null, 'status' => 'active', 'approved_at' => now()],
            $plan->key, ['name' => $d['admin_name'], 'email' => strtolower($d['admin_email']), 'password' => Str::password(40)]);
        $this->billing->start($tenant, $plan, $d['cycle'], null, isset($d['trial_days']) ? (int) $d['trial_days'] : null);
        // the first administrator sets their own password; the platform never knows it
        app(TenantContext::class)->run($tenant, fn () => Password::broker()->sendResetLink(['email' => strtolower($d['admin_email'])]));
        $this->audit->record('platform.institution_created', $tenant, ['by' => $by->id, 'plan' => $plan->key]);

        return $tenant;
    }

    public function updateInstitution(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'], 'domains' => ['sometimes', 'array', 'max:10'], 'domains.*' => ['string', 'max:190', 'regex:/^[a-z0-9.-]+$/i'],
            'contact_name' => ['nullable', 'string', 'max:190'], 'contact_email' => ['nullable', 'email'], 'contact_phone' => ['nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'in:active,suspended'], 'suspension_reason' => ['nullable', 'in:non_payment,request,abuse,other'],
            'overrides' => ['sometimes', 'array'], 'overrides.*' => ['nullable', 'boolean'],
        ]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $tenant) {
            foreach ($data['overrides'] ?? [] as $key => $on) {
                abort_unless($this->modules->exists($key), 422);
                $on === null ? $tenant->moduleOverrides()->where('module', $key)->delete() : $tenant->moduleOverrides()->updateOrCreate(['module' => $key], ['enabled' => $on, 'expires_at' => null]);
            }
            if (! empty($data['overrides'])) {
                app(\App\Services\Billing\Entitlements::class)->assertModuleAllowance($tenant->refresh());
            }
        });
        unset($data['overrides']);
        if (($data['status'] ?? null) === 'active') {
            $data['suspension_reason'] = null;
        }
        $tenant->update($data);
        $this->audit->record('platform.institution_updated', $tenant, array_diff_key($data, ['domains' => 1]));

        return back()->with('success', 'Saved.');
    }

    public function changeSubscription(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'exists:plans,id'], 'cycle' => ['required', Billing::cycleRule()], 'price' => ['nullable', 'integer', 'min:0'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:90']]);
        $sub = $this->billing->start($tenant, Plan::findOrFail($data['plan_id']), $data['cycle'], isset($data['price']) ? (int) $data['price'] * 100 : null, (int) ($data['trial_days'] ?? 0));
        Entitlements::forget($tenant->id);
        $this->audit->record('platform.subscription_changed', $tenant, ['plan' => $sub->plan_id, 'cycle' => $sub->cycle, 'price' => $sub->price]);

        return back()->with('success', 'Subscription changed.');
    }

    public function invoice(Request $request, Tenant $tenant): RedirectResponse
    {
        $sub = $this->subscriptionOf($tenant->id);
        abort_unless($sub && $sub->price > 0, 422, 'The institution has no paid subscription.');
        $from = CarbonImmutable::parse($sub->current_period_end);
        $inv = $this->billing->invoice($sub, $from, $this->billing->periodEnd($from, $sub->cycle), CarbonImmutable::now()->addDays(14));
        $this->billing->tell($tenant, 'Invoice '.$inv->number, 'Amount: '.Billing::money($inv->amount, $inv->currency).'.');

        return back()->with('success', "Invoice {$inv->number} issued.");
    }

    public function voidInvoice(Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->status === 'paid', 409, 'A paid invoice cannot be cancelled. Refund it instead.');
        $invoice->update(['status' => 'void']);
        TenantAddon::where('invoice_id', $invoice->id)->where('status', 'pending')->update(['status' => 'ended']);
        $this->audit->record('platform.invoice_voided', null, ['invoice' => $invoice->number]);

        return back()->with('success', 'Invoice cancelled.');
    }

    /** Bank transfers and cheques are recorded by hand, with the bank reference. */
    public function recordPayment(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:100'], 'amount' => ['required', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:255'],
            'provider' => ['required', 'in:bank_transfer,manual']]);
        $this->billing->pay($invoice, $data['provider'], 'BANK-'.$data['reference'], (int) round($data['amount'] * 100), $request->user(), [], $data['note'] ?? null);
        $this->audit->record('platform.payment_recorded', null, ['invoice' => $invoice->number, 'reference' => $data['reference']]);

        return back()->with('success', 'Payment recorded and the subscription extended.');
    }

    public function applications(): Response
    {
        return Inertia::render('platform/applications', [
            'applications' => InstitutionApplication::with('plan:id,name')->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->latest('id')->limit(200)->get(),
        ]);
    }

    public function decideApplication(Request $request, InstitutionApplication $application, TenantProvisioner $provisioner): RedirectResponse
    {
        abort_unless($application->status === 'pending', 409, 'Already decided.');
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255'], 'slug' => ['nullable', 'alpha_dash', 'max:40'], 'trial_days' => ['nullable', 'integer', 'min:0', 'max:90']]);
        if (! $data['approve']) {
            $application->update(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null]);

            return back()->with('success', 'Application declined.');
        }
        $slug = strtolower($data['slug'] ?? $application->slug);
        abort_if(Tenant::where('slug', $slug)->exists(), 422, "The short name $slug is taken; choose another.");
        $tenant = $this->createInstitution(['name' => $application->institution, 'slug' => $slug, 'plan_id' => $application->plan_id ?? Plan::where('key', 'starter')->value('id'),
            'cycle' => $application->cycle, 'trial_days' => $data['trial_days'] ?? null, 'admin_name' => $application->contact_name,
            'admin_email' => $application->contact_email, 'contact_phone' => $application->contact_phone], $provisioner, $request->user());
        $application->update(['status' => 'approved', 'tenant_id' => $tenant->id, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null]);

        return redirect()->route('platform.institution', $tenant)->with('success', "{$tenant->name} approved. A set-up email has gone to {$application->contact_email}.");
    }

    public function plans(): Response
    {
        return Inertia::render('platform/plans', [
            'plans' => Plan::orderBy('sort')->get(),
            'modules' => collect($this->modules->all())->map(fn ($m, $key) => ['key' => $key, 'name' => $m['name'], 'always_on' => (bool) ($m['always_on'] ?? false)])->values(),
            'limitKeys' => collect(Entitlements::LIMITS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l[0], 'unit' => $l[1], 'about' => $l[2]])->values(),
            'defaults' => ['trial_days' => PlatformSettings::get('trial_days'), 'grace_days' => PlatformSettings::get('grace_days'), 'upload_mb' => PlatformSettings::get('default_upload_mb')],
        ]);
    }

    public function savePlan(Request $request, ?Plan $plan = null): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'alpha_dash', 'max:40', Rule::unique('plans', 'key')->ignore($plan?->id)], 'name' => ['required', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'],
            'modules' => ['array'], 'modules.*' => ['string'], 'currency' => ['required', 'in:NGN,USD,GHS,KES,ZAR'],
            'price_monthly' => ['required', 'numeric', 'min:0'], 'price_yearly' => ['required', 'numeric', 'min:0'], 'student_limit' => ['nullable', 'integer', 'min:1'],
            'price_quarterly' => ['nullable', 'numeric', 'min:0'], 'price_biannual' => ['nullable', 'numeric', 'min:0'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'grace_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'limits' => ['array'], 'limits.*' => ['nullable', 'integer', 'min:0'],
            'addon_prices' => ['array'], 'addon_prices.*.per' => ['nullable', 'integer', 'min:1'], 'addon_prices.*.price' => ['nullable', 'numeric', 'min:0'],
            'features' => ['array', 'max:12'], 'features.*' => ['string', 'max:120'], 'is_public' => ['boolean'], 'highlighted' => ['boolean'], 'sort' => ['nullable', 'integer'],
        ]);
        foreach ($data['modules'] ?? [] as $m) {
            abort_unless($this->modules->exists($m), 422, "Unknown module $m");
        }
        $data['price_monthly'] = (int) round($data['price_monthly'] * 100);
        $data['price_yearly'] = (int) round($data['price_yearly'] * 100);
        foreach (['price_quarterly', 'price_biannual'] as $k) {
            $data[$k] = isset($data[$k]) && $data[$k] !== '' ? (int) round($data[$k] * 100) : null;
        }
        $data['limits'] = $this->cleanLimits($data['limits'] ?? []);
        $data['student_limit'] = $data['limits']['students'] ?? null; // kept in step for older screens
        $data['addon_prices'] = collect($data['addon_prices'] ?? [])->filter(fn ($p, $k) => isset(Entitlements::LIMITS[$k]) && ! empty($p['per']) && isset($p['price']) && $p['price'] !== '')
            ->map(fn ($p) => ['per' => (int) $p['per'], 'price' => (int) round($p['price'] * 100)])->all() ?: null;
        $saved = ($plan ?? new Plan)->fill($data);
        $saved->save();
        Tenant::where('plan_id', $saved->id)->pluck('id')->each(fn ($id) => Entitlements::forget($id));
        $this->audit->record('platform.plan_saved', null, ['plan' => $data['key']]);

        return back()->with('success', 'Package saved. New limits apply at once; existing subscriptions keep their price until renewal.');
    }

    /** @return array<string, int|null> only known limit keys; empty = unlimited (null) */
    private function cleanLimits(array $in): array
    {
        $out = [];
        foreach (Entitlements::LIMITS as $k => $_) {
            if (array_key_exists($k, $in)) {
                $out[$k] = $in[$k] === null || $in[$k] === '' ? null : (int) $in[$k];
            }
        }

        return $out;
    }

    /** Enterprise / custom limits for one institution, replacing the package's for the keys given. */
    public function saveLimits(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['limits' => ['array'], 'limits.*' => ['nullable', 'integer', 'min:0'], 'use_package' => ['array'], 'use_package.*' => ['string'], 'note' => ['nullable', 'string', 'max:255']]);
        $sub = $this->subscriptionOf($tenant->id);
        abort_unless($sub, 422, 'The institution has no running subscription.');
        $limits = $this->cleanLimits($data['limits'] ?? []);
        foreach ($data['use_package'] ?? [] as $k) {
            unset($limits[$k]);
        }
        $before = $sub->limits;
        $sub->update(['limits' => $limits ?: null, 'note' => $data['note'] ?? $sub->note]);
        Entitlements::forget($tenant->id);
        SubscriptionEvent::record($tenant->id, 'limits_changed', ['from' => $before, 'to' => $limits ?: null], $sub->id);
        $this->audit->record('platform.limits_changed', $tenant, ['limits' => $limits]);

        return back()->with('success', 'Capacity saved. It applies at once.');
    }

    public function extend(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:730'], 'reason' => ['nullable', 'string', 'max:190']]);
        $sub = $this->subscriptionOf($tenant->id);
        abort_unless($sub, 422, 'The institution has no running subscription.');
        $this->billing->extend($sub, (int) $data['days'], $data['reason'] ?? null);
        $this->audit->record('platform.subscription_extended', $tenant, $data);

        return back()->with('success', 'Validity extended to '.$sub->fresh()->current_period_end->format('j M Y').'.');
    }

    public function cancelSubscription(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['when' => ['required', 'in:period_end,now,resume']]);
        $sub = $this->subscriptionOf($tenant->id);
        abort_unless($sub, 422, 'The institution has no running subscription.');
        $data['when'] === 'resume' ? $this->billing->resume($sub) : $this->billing->cancel($sub, $data['when'] === 'period_end');
        Entitlements::forget($tenant->id);
        $this->audit->record('platform.subscription_cancelled', $tenant, $data);

        return back()->with('success', 'Saved.');
    }

    /** Time-limited access to a module (trial of a feature, support case). Does not use the module allowance. */
    public function grantModule(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['module' => ['required', 'string'], 'days' => ['required', 'integer', 'min:1', 'max:365'], 'reason' => ['nullable', 'string', 'max:190']]);
        abort_unless($this->modules->exists($data['module']), 422, 'Unknown module.');
        $until = now()->addDays((int) $data['days']);
        $tenant->moduleOverrides()->updateOrCreate(['module' => $data['module']], ['enabled' => true, 'expires_at' => $until, 'reason' => $data['reason'] ?? null, 'granted_by' => $request->user()->id]);
        Entitlements::forget($tenant->id);
        SubscriptionEvent::record($tenant->id, 'module_granted', ['module' => $data['module'], 'until' => $until->toDateString(), 'reason' => $data['reason'] ?? null]);
        $this->audit->record('platform.module_granted', $tenant, $data);

        return back()->with('success', 'Access granted until '.$until->format('j M Y').'.');
    }

    public function revokeGrant(Tenant $tenant, string $module): RedirectResponse
    {
        $tenant->moduleOverrides()->where('module', $module)->whereNotNull('expires_at')->delete();
        Entitlements::forget($tenant->id);
        SubscriptionEvent::record($tenant->id, 'module_revoked', ['module' => $module, 'reason' => 'ended by the platform team']);
        $this->audit->record('platform.module_revoked', $tenant, ['module' => $module]);

        return back()->with('success', 'Temporary access ended.');
    }

    /** Extra capacity given by the platform team (contract, goodwill), without an invoice. */
    public function addAddon(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(['limit_key' => ['required', Rule::in(array_keys(Entitlements::LIMITS))], 'quantity' => ['required', 'integer', 'min:1'],
            'ends_at' => ['nullable', 'date', 'after:today'], 'note' => ['nullable', 'string', 'max:190']]);
        $a = TenantAddon::create($data + ['tenant_id' => $tenant->id, 'status' => 'active', 'starts_at' => now(), 'created_by' => $request->user()->id]);
        Entitlements::forget($tenant->id);
        SubscriptionEvent::record($tenant->id, 'addon_added', ['limit' => $a->limit_key, 'quantity' => $a->quantity, 'until' => $a->ends_at?->toDateString(), 'by' => 'platform']);
        $this->audit->record('platform.addon_added', $tenant, $data);

        return back()->with('success', 'Extra capacity added.');
    }

    public function endAddon(Tenant $tenant, TenantAddon $addon): RedirectResponse
    {
        abort_unless($addon->tenant_id === $tenant->id, 404);
        $addon->update(['status' => 'ended', 'ends_at' => now()]);
        Entitlements::forget($tenant->id);
        SubscriptionEvent::record($tenant->id, 'addon_ended', ['limit' => $addon->limit_key, 'quantity' => $addon->quantity, 'by' => 'platform']);
        $this->audit->record('platform.addon_ended', $tenant, ['addon' => $addon->id]);

        return back()->with('success', 'Extra capacity ended.');
    }

    public function document(Invoice $invoice, BillingDocuments $docs): \Symfony\Component\HttpFoundation\Response
    {
        return $docs->download($invoice);
    }

    public function settings(): Response
    {
        return Inertia::render('platform/settings', ['settings' => PlatformSettings::all()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'], 'grace_days' => ['required', 'integer', 'min:0', 'max:180'],
            'invoice_days_before' => ['required', 'integer', 'min:1', 'max:90'], 'reminder_days' => ['array', 'max:8'], 'reminder_days.*' => ['integer', 'min:1', 'max:90'],
            'default_upload_mb' => ['required', 'integer', 'min:1', 'max:5120'], 'billing_name' => ['nullable', 'string', 'max:120'],
            'billing_address' => ['nullable', 'string', 'max:500'], 'billing_email' => ['nullable', 'email', 'max:190'],
            'rate_user_per_minute' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'], 'rate_ip_per_minute' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'rate_key_per_minute' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'], 'rate_tenant_per_minute' => ['sometimes', 'required', 'integer', 'min:0', 'max:10000000'],
        ]);
        $data['reminder_days'] = array_values(array_unique(array_map('intval', $data['reminder_days'] ?? [])));
        rsort($data['reminder_days']);
        $data['billing_address'] ??= '';
        $data['billing_name'] ??= '';
        $data['billing_email'] ??= '';
        PlatformSettings::put($data, $request->user()->id);
        Tenant::pluck('id')->each(fn ($id) => Entitlements::forget($id));
        $this->audit->record('platform.settings_saved', null, array_diff_key($data, ['billing_address' => 1]));

        return back()->with('success', 'Settings saved.');
    }

    public function announcements(): Response
    {
        return Inertia::render('platform/announcements', [
            'announcements' => \App\Models\PlatformAnnouncement::latest('id')->limit(200)->get(),
            'institutions' => Tenant::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function saveAnnouncement(Request $request, ?\App\Models\PlatformAnnouncement $announcement = null): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'], 'body' => ['required', 'string', 'max:2000'], 'level' => ['required', 'in:info,warning,critical'],
            'audience' => ['required', 'in:everyone,admins,staff,students'], 'tenant_ids' => ['array'], 'tenant_ids.*' => ['integer', 'exists:tenants,id'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'], 'dismissible' => ['boolean'], 'notify' => ['boolean'],
        ]);
        $data['tenant_ids'] = array_map('intval', $data['tenant_ids'] ?? []) ?: null;
        $notify = (bool) ($data['notify'] ?? false);
        unset($data['notify']);
        $a = ($announcement ?? new \App\Models\PlatformAnnouncement(['created_by' => $request->user()->id]))->fill($data);
        $a->save();
        if ($notify) {
            // also a notification, inside each institution, to the people it is for
            foreach (Tenant::when($a->tenant_ids, fn ($q) => $q->whereIn('id', $a->tenant_ids))->where('status', 'active')->get() as $t) {
                app(\App\Support\Tenancy\TenantContext::class)->run($t, function () use ($a) {
                    User::query()->where('status', 'active')
                        ->when($a->audience === 'staff', fn ($q) => $q->where('user_type', 'staff'))
                        ->when($a->audience === 'students', fn ($q) => $q->where('user_type', 'student'))
                        ->when($a->audience === 'admins', fn ($q) => $q->permission(config('roles.privileged')))
                        ->chunkById(500, fn ($users) => \Illuminate\Support\Facades\Notification::send($users, new \App\Notifications\LmsNotice($a->title, \Illuminate\Support\Str::limit($a->body, 300), null, $a->level === 'critical', category: 'system')));
                });
            }
        }
        $this->audit->record('platform.announcement_saved', $a, ['title' => $a->title, 'notify' => $notify]);

        return back()->with('success', 'Announcement saved.');
    }

    public function deleteAnnouncement(\App\Models\PlatformAnnouncement $announcement): RedirectResponse
    {
        $announcement->delete();
        $this->audit->record('platform.announcement_deleted', null, ['title' => $announcement->title]);

        return back()->with('success', 'Announcement removed.');
    }

    public function saveMaintenance(Request $request): RedirectResponse
    {
        $data = $request->validate(['maintenance_mode' => ['required', 'boolean'], 'maintenance_message' => ['nullable', 'string', 'max:500']]);
        $data['maintenance_message'] ??= '';
        PlatformSettings::put($data, $request->user()->id);
        $this->audit->record('platform.maintenance', null, $data);

        return back()->with('success', $data['maintenance_mode'] ? 'Maintenance mode is on: institutions see the maintenance page.' : 'Maintenance mode is off.');
    }

    /** The platform's own name and look (each institution brands its own portal separately). */
    public function branding(): Response
    {
        return Inertia::render('platform/branding', ['brand' => PlatformSettings::brand(), 'settings' => array_intersect_key(PlatformSettings::all(),
            array_flip(['brand_name', 'brand_short_name', 'brand_tagline', 'brand_color', 'support_email']))]);
    }

    public function saveBranding(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_name' => ['required', 'string', 'max:120'], 'brand_short_name' => ['nullable', 'string', 'max:40'], 'brand_tagline' => ['nullable', 'string', 'max:80'],
            'brand_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'support_email' => ['nullable', 'email', 'max:190'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'], 'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:256'],
            'remove_logo' => ['boolean'], 'remove_favicon' => ['boolean'],
        ]);
        $values = array_map(fn ($v) => strip_tags((string) ($v ?? '')), array_intersect_key($data, array_flip(['brand_name', 'brand_short_name', 'brand_tagline', 'brand_color', 'support_email'])));
        foreach (['logo' => 'brand_logo_path', 'favicon' => 'brand_favicon_path'] as $field => $key) {
            if ($request->hasFile($field)) {
                $values[$key] = $request->file($field)->store('platform', 'public');
            } elseif ($request->boolean('remove_'.$field)) {
                $values[$key] = '';
            }
        }
        PlatformSettings::put($values, $request->user()->id);
        $this->audit->record('platform.branding_saved', null, array_diff_key($values, ['brand_logo_path' => 1, 'brand_favicon_path' => 1]));

        return back()->with('success', 'Platform branding saved.');
    }

    public function coupons(): Response
    {
        return Inertia::render('platform/coupons', [
            'coupons' => Coupon::latest('id')->get(),
            'plans' => Plan::orderBy('sort')->get(['id', 'name']),
            'cycles' => Billing::CYCLE_LABELS,
        ]);
    }

    public function saveCoupon(Request $request, ?Coupon $coupon = null): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('coupons', 'code')->ignore($coupon?->id)], 'description' => ['nullable', 'string', 'max:190'],
            'kind' => ['required', 'in:percent,fixed'], 'value' => ['required', 'numeric', 'min:0.01'], 'currency' => ['required', 'in:NGN,USD,GHS,KES,ZAR'],
            'plan_ids' => ['array'], 'plan_ids.*' => ['integer', 'exists:plans,id'], 'cycles' => ['array'], 'cycles.*' => [Billing::cycleRule()],
            'max_redemptions' => ['nullable', 'integer', 'min:1'], 'per_tenant' => ['required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'active' => ['boolean'],
        ]);
        abort_if($data['kind'] === 'percent' && $data['value'] > 100, 422, 'A percentage cannot be more than 100.');
        $data['code'] = mb_strtoupper($data['code']);
        $data['value'] = $data['kind'] === 'percent' ? (int) $data['value'] : (int) round($data['value'] * 100);
        $data['plan_ids'] = array_map('intval', $data['plan_ids'] ?? []) ?: null;
        $data['cycles'] = ($data['cycles'] ?? []) ?: null;
        ($coupon ?? new Coupon)->fill($data)->save();
        $this->audit->record('platform.coupon_saved', null, ['code' => $data['code']]);

        return back()->with('success', 'Code saved.');
    }

    public function payments(): Response
    {
        return Inertia::render('platform/payments', [
            'payments' => Payment::latest('id')->limit(300)->get()->map(fn ($p) => $p->only(['id', 'provider', 'reference', 'status', 'paid_at', 'note']) + ['institution' => Tenant::find($p->tenant_id)?->name, 'tenant_id' => $p->tenant_id, 'amount_label' => Billing::money($p->amount, $p->currency)]),
            'invoices' => Invoice::where('status', 'open')->orderBy('due_at')->limit(100)->get()->map(fn ($i) => $i->only(['id', 'number', 'due_at', 'tenant_id']) + ['institution' => Tenant::find($i->tenant_id)?->name, 'amount_label' => Billing::money($i->amount, $i->currency), 'overdue' => $i->due_at->isPast()]),
        ]);
    }

    public function admins(): Response
    {
        return Inertia::render('platform/admins', ['admins' => User::whereNull('tenant_id')->where('is_platform_admin', true)->orderBy('name')->get(['id', 'name', 'email', 'status', 'two_factor_confirmed_at', 'created_at'])]);
    }

    public function storeAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:190'], 'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->whereNull('tenant_id')]]);
        $u = User::forceCreate(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Str::password(40), 'is_platform_admin' => true, 'user_type' => 'platform',
            'tenant_id' => null, 'status' => 'active', 'email_verified_at' => now()]);
        Password::broker()->sendResetLink(['email' => $u->email]);
        $this->audit->record('platform.admin_added', $u, ['by' => $request->user()->id]);

        return back()->with('success', "Invitation sent to {$u->email}.");
    }

    public function disableAdmin(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isPlatformAdmin() && $user->id !== $request->user()->id, 422, 'You cannot disable yourself.');
        $user->forceFill(['status' => $user->status === 'active' ? 'inactive' : 'active'])->save();
        $this->audit->record('platform.admin_toggled', $user, ['status' => $user->status]);

        return back()->with('success', 'Saved.');
    }

    /** The security self-check: last results and a button to run it again. */
    public function security(): Response
    {
        $last = \Illuminate\Support\Facades\Cache::get('security:check');

        return Inertia::render('platform/security', ['last' => $last]);
    }

    public function runSecurity(\App\Services\Security\SecuritySelfCheck $check): RedirectResponse
    {
        $r = $check->run();
        $this->audit->record('platform.security_check', null, ['fail' => count(array_filter($r, fn ($x) => $x['status'] === 'fail'))]);

        return back()->with('success', 'Security check finished.');
    }
}
