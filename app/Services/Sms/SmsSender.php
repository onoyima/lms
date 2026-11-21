<?php

namespace App\Services\Sms;

use App\Models\Tenant;
use App\Support\Platform\PlatformSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Text messages through the institution's SMS provider (or the platform's, when it shares one).
 * Providers: Africa's Talking, Termii, Twilio, or any HTTP gateway (a URL with {to} and {message}).
 * Message contents are not stored; the log keeps who, when, why and whether it was sent.
 */
class SmsSender
{
    public const PROVIDERS = [
        'africastalking' => "Africa's Talking",
        'termii' => 'Termii',
        'twilio' => 'Twilio',
        'http' => 'Any HTTP gateway',
    ];

    /** @return array{provider: string, username: string, key: string, sender: string, url: string, source: string}|null */
    public static function settings(?Tenant $tenant = null): ?array
    {
        $tenant ??= tenant();
        $own = (array) data_get($tenant?->settings, 'sms', []);
        if (! empty($own['key']) && ! empty($own['provider'])) {
            return self::shape($own, 'institution');
        }
        $p = PlatformSettings::all();
        if ($p['sms_key'] && $p['sms_shared'] && ($own['use_platform'] ?? true)) {
            return self::shape(['provider' => $p['sms_provider'], 'username' => $p['sms_username'], 'key' => $p['sms_key'], 'sender' => $p['sms_sender'], 'url' => $p['sms_url']], 'platform');
        }

        return null;
    }

    public static function enabled(?Tenant $tenant = null): bool
    {
        return self::settings($tenant) !== null;
    }

    /** Nigerian and other numbers to the international form (+234…); null if it does not look like a mobile number. */
    public static function normalise(?string $phone, string $country = '234'): ?string
    {
        $d = preg_replace('/[^\d+]/', '', (string) $phone);
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '+')) {
            $d = substr($d, 1);
        } elseif (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '0')) {
            $d = $country.substr($d, 1);
        }

        return preg_match('/^\d{10,15}$/', $d) ? '+'.$d : null;
    }

    /** @return bool sent */
    public function send(string $to, string $message, string $purpose, ?int $userId = null, ?Tenant $tenant = null, ?array $settings = null): bool
    {
        // the public demo never sends text messages (it would use the platform's shared credit)
        if (($tenant ?? tenant())?->isDemo()) {
            return false;
        }
        $s = $settings ?? self::settings($tenant);
        $number = self::normalise($to);
        if (! $s || ! $number) {
            return false;
        }
        $message = mb_substr($message, 0, 459);
        try {
            $res = match ($s['provider']) {
                'africastalking' => Http::asForm()->withHeaders(['apiKey' => $s['key'], 'Accept' => 'application/json'])->timeout(20)
                    ->post($s['url'] ?: 'https://api.africastalking.com/version1/messaging', array_filter(['username' => $s['username'], 'to' => $number, 'message' => $message, 'from' => $s['sender'] ?: null])),
                'termii' => Http::timeout(20)->post($s['url'] ?: 'https://api.ng.termii.com/api/sms/send',
                    ['api_key' => $s['key'], 'to' => ltrim($number, '+'), 'from' => $s['sender'] ?: 'N-Alert', 'sms' => $message, 'type' => 'plain', 'channel' => $s['sender'] ? 'generic' : 'dnd']),
                'twilio' => Http::asForm()->withBasicAuth($s['username'], $s['key'])->timeout(20)
                    ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($s['username']).'/Messages.json', ['To' => $number, 'From' => $s['sender'], 'Body' => $message]),
                default => Http::timeout(20)->get(strtr($s['url'], ['{to}' => rawurlencode($number), '{message}' => rawurlencode($message), '{key}' => rawurlencode($s['key']), '{sender}' => rawurlencode($s['sender'])])),
            };
            $ok = $res->successful();
            $error = $ok ? null : mb_substr($res->status().' '.$res->body(), 0, 250);
            $ref = $ok ? (string) ($res->json('SMSMessageData.Recipients.0.messageId') ?? $res->json('message_id') ?? $res->json('sid') ?? '') : null;
        } catch (\Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 250);
            $ref = null;
        }
        DB::table('sms_messages')->insert([
            'tenant_id' => ($tenant ?? tenant())?->id, 'user_id' => $userId, 'to' => substr($number, 0, -4).'****', 'purpose' => mb_substr($purpose, 0, 40),
            'status' => $ok ? 'sent' : 'failed', 'provider' => $s['provider'], 'provider_ref' => $ref ? mb_substr($ref, 0, 120) : null, 'error' => $error,
            'parts' => (int) ceil(mb_strlen($message) / 153) ?: 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $ok;
    }

    public static function seal(string $v): string
    {
        return Crypt::encryptString($v);
    }

    private static function shape(array $c, string $source): ?array
    {
        try {
            $key = Crypt::decryptString((string) $c['key']);
        } catch (\Throwable) {
            return null;
        }

        return ['provider' => array_key_exists($c['provider'] ?? '', self::PROVIDERS) ? $c['provider'] : 'http', 'username' => (string) ($c['username'] ?? ''),
            'key' => $key, 'sender' => (string) ($c['sender'] ?? ''), 'url' => (string) ($c['url'] ?? ''), 'source' => $source];
    }
}
