<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiSettings;
use App\Services\Audit\AuditLogger;
use App\Support\Platform\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI settings: the platform's own AI (offered to institutions), and each institution's choice — use
 * the platform's, or its own provider and key, and which AI features are on. Keys are stored
 * encrypted and never sent back to the browser.
 */
class AiSettingsController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function institution(): Response
    {
        $own = (array) data_get(tenant()->settings, 'ai', []);
        $p = PlatformSettings::all();
        $active = AiSettings::resolve();

        return Inertia::render('admin/ai', [
            'scope' => 'institution',
            'current' => ['provider' => $own['provider'] ?? 'anthropic', 'base_url' => $own['base_url'] ?? '', 'model' => $own['model'] ?? '', 'has_key' => ! empty($own['key']),
                'use_platform' => (bool) ($own['use_platform'] ?? true), 'features' => array_replace(array_fill_keys(array_keys(AiSettings::FEATURES), true), (array) ($own['features'] ?? []))],
            'platformOffers' => (bool) ($p['ai_key'] && $p['ai_shared']),
            'active' => $active ? ['source' => $active['source'], 'provider' => AiSettings::PROVIDERS[$active['provider']], 'model' => $active['model']] : null,
            'providers' => AiSettings::PROVIDERS, 'defaults' => ['model' => AiSettings::DEFAULT_MODEL, 'url' => AiSettings::DEFAULT_URL], 'features' => AiSettings::FEATURES,
        ]);
    }

    public function saveInstitution(Request $request): RedirectResponse
    {
        $data = $this->validated($request) + $request->validate(['use_platform' => ['boolean'], 'features' => ['array'], 'features.*' => ['boolean']]);
        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        $ai = (array) ($settings['ai'] ?? []);
        $ai = array_replace($ai, ['provider' => $data['provider'], 'base_url' => (string) ($data['base_url'] ?? ''), 'model' => (string) ($data['model'] ?? ''),
            'use_platform' => (bool) ($data['use_platform'] ?? true), 'features' => array_intersect_key(array_map('boolval', (array) ($data['features'] ?? [])), AiSettings::FEATURES)]);
        if (! empty($data['key'])) {
            $ai['key'] = AiSettings::seal($data['key']);
        }
        if (! empty($data['remove_key'])) {
            unset($ai['key']);
        }
        $settings['ai'] = $ai;
        $tenant->forceFill(['settings' => $settings])->save();
        $this->audit->record('ai.settings_saved', $tenant, ['provider' => $ai['provider'], 'own_key' => ! empty($ai['key']), 'features' => $ai['features']]);

        return back()->with('success', 'AI settings saved.');
    }

    public function platform(): Response
    {
        $p = PlatformSettings::all();

        return Inertia::render('admin/ai', [
            'scope' => 'platform',
            'current' => ['provider' => $p['ai_provider'], 'base_url' => $p['ai_base_url'], 'model' => $p['ai_model'], 'has_key' => (bool) $p['ai_key'], 'shared' => (bool) $p['ai_shared']],
            'providers' => AiSettings::PROVIDERS, 'defaults' => ['model' => AiSettings::DEFAULT_MODEL, 'url' => AiSettings::DEFAULT_URL], 'features' => AiSettings::FEATURES,
        ]);
    }

    public function savePlatform(Request $request): RedirectResponse
    {
        $data = $this->validated($request) + $request->validate(['shared' => ['boolean']]);
        $put = ['ai_provider' => $data['provider'], 'ai_base_url' => (string) ($data['base_url'] ?? ''), 'ai_model' => (string) ($data['model'] ?? ''), 'ai_shared' => (bool) ($data['shared'] ?? true)];
        if (! empty($data['key'])) {
            $put['ai_key'] = AiSettings::seal($data['key']);
        }
        if (! empty($data['remove_key'])) {
            $put['ai_key'] = '';
        }
        PlatformSettings::put($put, $request->user()->id);
        $this->audit->record('platform.ai_settings_saved', null, ['provider' => $put['ai_provider'], 'shared' => $put['ai_shared']]);

        return back()->with('success', 'AI settings saved.');
    }

    /** Sends a tiny request with the settings in the form (or the saved key), and says whether it worked. */
    public function test(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $saved = $request->routeIs('platform.*') ? null : AiSettings::resolve();
        if ($request->routeIs('platform.*') && empty($data['key']) && ($k = PlatformSettings::all()['ai_key'])) {
            $data['key'] = \Illuminate\Support\Facades\Crypt::decryptString($k);
        }
        $key = $data['key'] ?? ($saved['key'] ?? '');
        if ($key === '') {
            return response()->json(['ok' => false, 'message' => 'Enter a key first.']);
        }
        $client = new AiClient(['provider' => $data['provider'], 'base_url' => rtrim($data['base_url'] ?: AiSettings::DEFAULT_URL[$data['provider']], '/'), 'model' => $data['model'] ?: AiSettings::DEFAULT_MODEL[$data['provider']], 'key' => $key]);
        try {
            $reply = $client->ask('Reply with the single word OK.', [['text' => 'Test']], 10);

            return response()->json(['ok' => true, 'message' => 'Connected. The AI replied: '.mb_substr(trim($reply['text']), 0, 40)]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'provider' => ['required', Rule::in(array_keys(AiSettings::PROVIDERS))],
            'base_url' => ['nullable', 'url', 'max:300'],
            'model' => ['nullable', 'string', 'max:120'],
            'key' => ['nullable', 'string', 'max:400'],
            'remove_key' => ['nullable', 'boolean'],
        ]);
    }
}
