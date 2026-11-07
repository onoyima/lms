<?php

namespace App\Services\Integrations;

use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Institutional e-mail (LMS 220): an institution can send through its own mail server (SMTP,
 * e.g. Microsoft 365 or Google Workspace relay) and from its own address. Applied whenever the
 * institution in context changes — web requests, queued jobs and console runs alike.
 */
class InstitutionMail
{
    private static ?array $platformDefaults = null;

    private static ?string $active = null;

    public static function apply(?Tenant $tenant): void
    {
        self::$platformDefaults ??= ['default' => config('mail.default'), 'from' => config('mail.from')];
        $c = $tenant ? (array) data_get($tenant->settings, 'integrations.mail', []) : [];
        $key = ! empty($c['enabled']) && ! empty($c['host']) ? $tenant->id.':'.md5(json_encode($c)) : null;
        if ($key === self::$active) {
            return;
        }
        self::$active = $key;
        if (! $key) {
            config(['mail.default' => self::$platformDefaults['default'], 'mail.from' => self::$platformDefaults['from']]);

            return;
        }
        try {
            $password = ! empty($c['secret']) ? Crypt::decryptString($c['secret']) : null;
        } catch (\Throwable) {
            $password = null;
        }
        config([
            'mail.mailers.institution' => ['transport' => 'smtp', 'host' => $c['host'], 'port' => (int) ($c['port'] ?? 587),
                'scheme' => ($c['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : null, 'username' => $c['username'] ?? null, 'password' => $password, 'timeout' => 20],
            'mail.default' => 'institution',
            'mail.from' => ['address' => $c['from_address'] ?: self::$platformDefaults['from']['address'], 'name' => $c['from_name'] ?: ($tenant->name ?? self::$platformDefaults['from']['name'])],
        ]);
        Mail::purge('institution');
    }

    /** For tests and long-running workers. */
    public static function reset(): void
    {
        self::$active = null;
        self::$platformDefaults = null;
    }
}
