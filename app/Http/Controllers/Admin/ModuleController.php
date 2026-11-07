<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Modules\ModuleRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform administrators choose each institution's plan and switch individual
 * modules on (add-ons) or off. Institution staff cannot change their own subscription.
 */
class ModuleController extends Controller
{
    public function __construct(private ModuleRegistry $modules) {}

    public function index(): Response
    {
        $tenants = Tenant::with(['plan', 'moduleOverrides'])->orderBy('name')->get();

        return Inertia::render('admin/modules', [
            'plans' => Plan::orderBy('sort')->get(['id', 'key', 'name', 'description', 'modules']),
            'modules' => collect($this->modules->all())->map(fn ($m, $key) => [
                'key' => $key, 'name' => $m['name'], 'description' => $m['description'],
                'always_on' => (bool) ($m['always_on'] ?? false), 'requires' => $m['requires'] ?? [], 'items' => $m['items'] ?? null,
            ])->values(),
            'tenants' => $tenants->map(fn (Tenant $t) => [
                'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status,
                'plan_id' => $t->plan_id,
                'enabled' => $this->modules->enabledFor($t),
                'overrides' => $t->moduleOverrides->mapWithKeys(fn ($o) => [$o->module => $o->enabled]),
            ]),
        ]);
    }

    public function update(Request $request, Tenant $tenant, AuditLogger $audit, TenantContext $context): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'overrides' => ['array'],
            'overrides.*' => ['nullable', 'boolean'],
        ]);

        foreach (array_keys($data['overrides'] ?? []) as $key) {
            abort_unless($this->modules->exists($key), 422, "Unknown module $key");
        }

        $before = $this->modules->enabledFor($tenant);
        \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $data) {
            $tenant->update(['plan_id' => $data['plan_id'] ?? null]);
            foreach ($data['overrides'] ?? [] as $key => $enabled) {
                if ($enabled === null) {
                    $tenant->moduleOverrides()->where('module', $key)->delete();
                } else {
                    $tenant->moduleOverrides()->updateOrCreate(['module' => $key], ['enabled' => $enabled, 'expires_at' => null]);
                }
            }
            \App\Services\Billing\Entitlements::forget($tenant->id);
            app(\App\Services\Billing\Entitlements::class)->assertModuleAllowance($tenant->refresh()); // rolls everything back when over the allowance
        });

        $tenant->refresh()->load(['plan', 'moduleOverrides']);
        $after = $this->modules->enabledFor($tenant);

        $context->run($tenant, fn () => $audit->record('modules.changed', $tenant, [
            'plan' => $tenant->plan?->key,
            'switched_on' => array_values(array_diff($after, $before)),
            'switched_off' => array_values(array_diff($before, $after)),
        ]));

        return back()->with('success', "Modules updated for {$tenant->name}.");
    }
}
