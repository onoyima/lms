<?php

namespace App\Services\Integrations;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;

/**
 * Settings for outside services an institution connects (plagiarism checker, analytics warehouse).
 * Kept in tenants.settings['integrations']; shared secrets are encrypted and never sent to the browser.
 */
class Connectors
{
    public const DEFAULTS = [
        'plagiarism' => ['enabled' => false, 'name' => '', 'url' => '', 'secret' => '', 'auto' => false],
        'analytics' => ['enabled' => false, 'url' => '', 'secret' => '', 'include_personal' => false],
        // the institution's own mail server (LMS 220)
        'mail' => ['enabled' => false, 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'secret' => '', 'from_address' => '', 'from_name' => ''],
        // the institution's notification service (SMS gateway, Teams/Slack, campus app): signed copies of notices
        'notify' => ['enabled' => false, 'url' => '', 'secret' => '', 'urgent_only' => false],
        // the institution's records system through its directory bridge: people, courses, registrations, sign-in
        'directory' => ['enabled' => false, 'url' => '', 'client_id' => '', 'secret' => '', 'include_applicants' => false, 'deactivate_missing' => true,
            'follow_current_semester' => true, 'sso' => true, 'sso_provider_id' => null],
    ];

    public function __construct(private TenantContext $context) {}

    public function get(string $name): array
    {
        $raw = (array) data_get($this->context->get()?->settings, "integrations.$name", []);
        $c = array_replace(self::DEFAULTS[$name], $raw);
        try {
            $c['secret'] = $c['secret'] ? Crypt::decryptString($c['secret']) : '';
        } catch (\Throwable) {
            $c['secret'] = '';
        }

        return $c;
    }

    /** For the settings page: everything except the secret itself. */
    public function public(string $name): array
    {
        $c = $this->get($name);
        $c['has_secret'] = $c['secret'] !== '';
        unset($c['secret']);

        return $c;
    }

    public function save(string $name, array $values): void
    {
        $tenant = $this->context->get();
        $current = (array) data_get($tenant->settings, "integrations.$name", []);
        $next = array_intersect_key(array_replace(self::DEFAULTS[$name], $current, $values), self::DEFAULTS[$name]);
        // a blank secret field keeps the stored one
        $next['secret'] = ($values['secret'] ?? '') !== '' ? Crypt::encryptString($values['secret']) : ($current['secret'] ?? '');
        $settings = (array) $tenant->settings;
        data_set($settings, "integrations.$name", $next);
        $tenant->settings = $settings;
        $tenant->save();
    }

    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * A host the server may connect to on an institution's behalf (mail server, hall equipment).
     * Loopback, link-local and this server's own cloud metadata addresses are never allowed; private
     * campus addresses only when PLATFORM_ALLOW_PRIVATE_TARGETS is on (campus installs; off for shared hosting).
     */
    public static function safeHost(string $host): bool
    {
        $host = trim($host);
        if (! preg_match('/^[a-z0-9.-]{1,190}$/i', $host) && ! filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) || str_starts_with($ip, '169.254.') || str_starts_with($ip, '127.') || $ip === '0.0.0.0') {
                return false;
            }
            if (! config('platform.allow_private_targets') && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /** Outside URLs must be https and must not point into private networks (SSRF protection). */
    public static function safeUrl(string $url): bool
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return app()->environment('local', 'testing') && ($p['scheme'] ?? '') === 'http' && ! empty($p['host']);
        }
        $ips = filter_var($p['host'], FILTER_VALIDATE_IP) ? [$p['host']] : (gethostbynamel($p['host']) ?: []);
        if (! $ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }
}
