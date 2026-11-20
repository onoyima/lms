<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Sms\SmsSender;
use App\Support\Platform\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Text messages: the platform's provider (optionally shared) and each institution's own. Keys stay encrypted. */
class SmsSettingsController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function institution(): Response
    {
        $own = (array) data_get(tenant()->settings, 'sms', []);
        $p = PlatformSettings::all();
        $active = SmsSender::settings();

        return Inertia::render('admin/sms', [
            'scope' => 'institution',
            'current' => ['provider' => $own['provider'] ?? 'termii', 'username' => $own['username'] ?? '', 'sender' => $own['sender'] ?? '', 'url' => $own['url'] ?? '',
                'has_key' => ! empty($own['key']), 'use_platform' => (bool) ($own['use_platform'] ?? true)],
            'platformOffers' => (bool) ($p['sms_key'] && $p['sms_shared']),
            'active' => $active ? ['source' => $active['source'], 'provider' => SmsSender::PROVIDERS[$active['provider']]] : null,
            'providers' => SmsSender::PROVIDERS,
            'log' => DB::table('sms_messages')->where('tenant_id', tenant_id())->latest('id')->limit(30)->get(['to', 'purpose', 'status', 'error', 'created_at']),
            'month' => DB::table('sms_messages')->where('tenant_id', tenant_id())->where('created_at', '>=', now()->startOfMonth())->selectRaw("COALESCE(SUM(parts),0) as parts, COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END),0) as failed")->first(),
        ]);
    }

    public function saveInstitution(Request $request): RedirectResponse
    {
        $data = $this->validated($request) + $request->validate(['use_platform' => ['boolean']]);
        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        $sms = array_replace((array) ($settings['sms'] ?? []), ['provider' => $data['provider'], 'username' => (string) ($data['username'] ?? ''), 'sender' => (string) ($data['sender'] ?? ''),
            'url' => (string) ($data['url'] ?? ''), 'use_platform' => (bool) ($data['use_platform'] ?? true)]);
        if (! empty($data['key'])) {
            $sms['key'] = SmsSender::seal($data['key']);
        }
        if (! empty($data['remove_key'])) {
            unset($sms['key']);
        }
        $settings['sms'] = $sms;
        $tenant->forceFill(['settings' => $settings])->save();
        $this->audit->record('sms.settings_saved', $tenant, ['provider' => $sms['provider'], 'own_key' => ! empty($sms['key'])]);

        return back()->with('success', 'Text message settings saved.');
    }

    public function platform(): Response
    {
        $p = PlatformSettings::all();

        return Inertia::render('admin/sms', [
            'scope' => 'platform',
            'current' => ['provider' => $p['sms_provider'], 'username' => $p['sms_username'], 'sender' => $p['sms_sender'], 'url' => $p['sms_url'], 'has_key' => (bool) $p['sms_key'], 'shared' => (bool) $p['sms_shared']],
            'providers' => SmsSender::PROVIDERS,
        ]);
    }

    public function savePlatform(Request $request): RedirectResponse
    {
        $data = $this->validated($request) + $request->validate(['shared' => ['boolean']]);
        $put = ['sms_provider' => $data['provider'], 'sms_username' => (string) ($data['username'] ?? ''), 'sms_sender' => (string) ($data['sender'] ?? ''), 'sms_url' => (string) ($data['url'] ?? ''), 'sms_shared' => (bool) ($data['shared'] ?? false)];
        if (! empty($data['key'])) {
            $put['sms_key'] = SmsSender::seal($data['key']);
        }
        if (! empty($data['remove_key'])) {
            $put['sms_key'] = '';
        }
        PlatformSettings::put($put, $request->user()->id);
        $this->audit->record('platform.sms_settings_saved', null, ['provider' => $put['sms_provider'], 'shared' => $put['sms_shared']]);

        return back()->with('success', 'Text message settings saved.');
    }

    /** Sends one test message with the form's settings (or the saved key). */
    public function test(Request $request, SmsSender $sms): JsonResponse
    {
        $data = $this->validated($request) + $request->validate(['to' => ['required', 'string', 'max:32']]);
        $platform = $request->routeIs('platform.*');
        $savedKey = $platform ? PlatformSettings::all()['sms_key'] : (string) data_get(tenant()->settings, 'sms.key', '');
        $key = $data['key'] ?? ($savedKey ? Crypt::decryptString($savedKey) : '');
        if ($key === '') {
            return response()->json(['ok' => false, 'message' => 'Enter a key first.']);
        }
        if (! SmsSender::normalise($data['to'])) {
            return response()->json(['ok' => false, 'message' => 'That does not look like a mobile number.']);
        }
        $ok = $sms->send($data['to'], 'Test message from your exams platform. Text messages are working.', 'test', $request->user()->id, $platform ? null : tenant(),
            ['provider' => $data['provider'], 'username' => (string) ($data['username'] ?? ''), 'key' => $key, 'sender' => (string) ($data['sender'] ?? ''), 'url' => (string) ($data['url'] ?? ''), 'source' => 'test']);
        $error = $ok ? null : DB::table('sms_messages')->latest('id')->value('error');

        return response()->json(['ok' => $ok, 'message' => $ok ? 'Sent. Check the phone.' : 'Not sent: '.$error]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'provider' => ['required', Rule::in(array_keys(SmsSender::PROVIDERS))],
            'username' => ['nullable', 'string', 'max:120'],
            'sender' => ['nullable', 'string', 'max:20'],
            'url' => ['nullable', 'string', 'max:500', 'required_if:provider,http'],
            'key' => ['nullable', 'string', 'max:400'],
            'remove_key' => ['nullable', 'boolean'],
        ]);
    }
}
