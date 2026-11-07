<?php

namespace App\Support\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide rules that platform administrators change from the console (never hardcoded):
 * trial length, grace period, when renewal invoices go out, renewal reminders, default upload size.
 */
class PlatformSettings
{
    public const DEFAULTS = [
        'trial_days' => 30,
        'grace_days' => 14,
        'invoice_days_before' => 14,
        'reminder_days' => [14, 7, 3, 1],
        'default_upload_mb' => 100,
        'billing_name' => '',               // blank = the platform's name
        // the platform's own identity (the service itself; each institution brands its own portal separately)
        'brand_name' => '',                 // blank = APP_NAME
        'brand_short_name' => '',
        'brand_tagline' => 'Exams & learning',
        'brand_color' => '#1d3557',
        'brand_logo_path' => '',
        'brand_favicon_path' => '',
        'support_email' => '',
        'billing_address' => '',
        'billing_email' => '',
        'maintenance_mode' => false,
        // requests per minute (0 = no limit)
        'rate_user_per_minute' => 1200,
        'rate_ip_per_minute' => 600,
        'rate_key_per_minute' => 600,
        'rate_tenant_per_minute' => 60000,
        'maintenance_message' => '',
    ];

    /** @var array{0: float, 1: array}|null read once per request (and at most every 30 s in long-running workers) */
    private static ?array $memo = null;

    public static function all(): array
    {
        if (self::$memo && microtime(true) - self::$memo[0] < 30) {
            return self::$memo[1];
        }
        $stored = Cache::remember('platform-settings', 300, fn () => DB::table('platform_settings')->pluck('value', 'key')->map(fn ($v) => json_decode((string) $v, true))->all());
        $all = array_replace(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        self::$memo = [microtime(true), $all];

        return $all;
    }

    /** What the platform looks like where no institution is in view (landing page, platform console, its emails and invoices). */
    public static function brand(): array
    {
        $s = self::all();
        // only what the platform administrator sets in Platform branding; never APP_NAME from the server's .env
        $name = trim((string) $s['brand_name']) ?: 'Exams & Learning';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $s['brand_color']) ? $s['brand_color'] : '#1d3557';

        return [
            'name' => $name,
            'short_name' => trim((string) $s['brand_short_name']) ?: $name,
            'tagline' => (string) $s['brand_tagline'],
            'brand' => $color,
            'brand_foreground' => \App\Support\Branding::readableTextOn($color),
            'logo_url' => $s['brand_logo_path'] ? asset('storage/'.$s['brand_logo_path']) : null,
            'favicon_url' => $s['brand_favicon_path'] ? asset('storage/'.$s['brand_favicon_path']) : null,
            'support_email' => (string) $s['support_email'],
        ];
    }

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget('platform-settings');
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public static function put(array $values, ?int $by = null): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $k => $v) {
            DB::table('platform_settings')->updateOrInsert(['key' => $k], ['value' => json_encode($v), 'updated_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
        }
        self::flush();
    }
}
