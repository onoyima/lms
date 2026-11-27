<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Media\ImageCompressor;
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

    public function update(Request $request, TenantContext $context, AuditLogger $audit, ImageCompressor $images): RedirectResponse
    {
        $tenant = $context->get();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'short_name' => ['nullable', 'string', 'max:60'],
            'theme' => ['nullable', 'in:sapphire,forest,plum,midnight'],
            'brand_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp'],
            // the rest of the white-label look
            'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico'],
            'remove_favicon' => ['boolean'],
            // the picture behind the glass: the theme's own, the institution's own photo, or none
            'background_mode' => ['nullable', 'in:theme,own,none'],
            'background' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=1200,min_height=500'],
            'login_title' => ['nullable', 'string', 'max:120'],
            'login_message' => ['nullable', 'string', 'max:500'],
            'welcome_message' => ['nullable', 'string', 'max:500'],
            'official_email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:300'],
        ]);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $images->store($request->file('logo'), "tenants/{$tenant->id}", 'public', ['max_width' => 1024, 'max_height' => 1024]);
        }
        $settings = (array) ($tenant->settings ?? []);
        $branding = (array) ($settings['branding'] ?? []);
        foreach (['secondary_color', 'login_title', 'login_message', 'welcome_message', 'official_email', 'phone', 'address'] as $k) {
            $branding[$k] = isset($data[$k]) && $data[$k] !== '' ? strip_tags((string) $data[$k]) : null;
        }
        if (! empty($data['theme'])) {
            $branding['theme'] = $data['theme'];
        }
        if ($request->hasFile('favicon')) {
            $branding['favicon_path'] = $images->storeAsFavicon($request->file('favicon'), "tenants/{$tenant->id}", 'public');
        } elseif ($request->boolean('remove_favicon')) {
            $branding['favicon_path'] = null;
        }
        if ($request->hasFile('background')) {
            $branding['background_path'] = $images->store($request->file('background'), "tenants/{$tenant->id}", 'public', ['max_width' => 2400, 'max_height' => 1400, 'max_bytes' => 900 * 1024]);
            $branding['background_mode'] = 'own';
        } elseif (! empty($data['background_mode'])) {
            $branding['background_mode'] = $data['background_mode'] === 'own' && empty($branding['background_path']) ? 'theme' : $data['background_mode'];
        }
        $settings['branding'] = array_filter($branding, fn ($v) => $v !== null);
        $tenant->update(['name' => $data['name'], 'short_name' => $data['short_name'] ?? null, 'brand_color' => $data['brand_color'], 'settings' => $settings]
            + (isset($data['logo_path']) ? ['logo_path' => $data['logo_path']] : []));
        $audit->record('branding.updated', $tenant, array_intersect_key($data, array_flip(['name', 'short_name', 'theme', 'brand_color', 'secondary_color', 'login_title', 'background_mode'])));

        return back()->with('success', 'Branding saved.');
    }
}
