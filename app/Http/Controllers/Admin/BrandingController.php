<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrandingController extends Controller
{
    public function edit(TenantContext $context): Response
    {
        $tenant = $context->get();

        return Inertia::render('admin/branding', [
            'branding' => $tenant->brandPayload() + ['domains' => $tenant->domains ?? []],
        ]);
    }

    public function update(Request $request, TenantContext $context, AuditLogger $audit): RedirectResponse
    {
        $tenant = $context->get();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'short_name' => ['nullable', 'string', 'max:60'],
            'brand_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            // the rest of the white-label look
            'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:256'],
            'remove_favicon' => ['boolean'],
            'login_title' => ['nullable', 'string', 'max:120'],
            'login_message' => ['nullable', 'string', 'max:500'],
            'welcome_message' => ['nullable', 'string', 'max:500'],
            'official_email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:300'],
        ]);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store("tenants/{$tenant->id}", 'public');
        }
        $settings = (array) ($tenant->settings ?? []);
        $branding = (array) ($settings['branding'] ?? []);
        foreach (['secondary_color', 'login_title', 'login_message', 'welcome_message', 'official_email', 'phone', 'address'] as $k) {
            $branding[$k] = isset($data[$k]) && $data[$k] !== '' ? strip_tags((string) $data[$k]) : null;
        }
        if ($request->hasFile('favicon')) {
            $branding['favicon_path'] = $request->file('favicon')->store("tenants/{$tenant->id}", 'public');
        } elseif ($request->boolean('remove_favicon')) {
            $branding['favicon_path'] = null;
        }
        $settings['branding'] = array_filter($branding, fn ($v) => $v !== null);
        $tenant->update(['name' => $data['name'], 'short_name' => $data['short_name'] ?? null, 'brand_color' => $data['brand_color'], 'settings' => $settings]
            + (isset($data['logo_path']) ? ['logo_path' => $data['logo_path']] : []));
        $audit->record('branding.updated', $tenant, array_intersect_key($data, array_flip(['name', 'short_name', 'brand_color', 'secondary_color', 'login_title'])));

        return back()->with('success', 'Branding saved.');
    }
}
