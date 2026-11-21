<?php

namespace Database\Seeders;

/**
 * Which sample institution the sample-data seeders fill: Veritas University for the tests and local
 * development, or the public demo institution (see `php artisan demo:reset`). Sample data only.
 */
class SampleInstitution
{
    public static string $slug = 'veritas';

    public static string $name = 'Veritas University, Abuja';

    public static string $short = 'Veritas';

    public static string $domain = 'veritas.local';

    /** Web addresses that open this institution (the public demo has none: visitors arrive through /demo). */
    public static array $hosts = ['localhost', '127.0.0.1'];

    /** Only the institution's own data (the demo never touches platform-wide settings, coupons or announcements). */
    public static bool $institutionOnly = false;

    public static function email(string $local): string
    {
        return $local.'@'.self::$domain;
    }

    /** Back to Veritas (the default), after filling the demo. */
    public static function reset(): void
    {
        self::$slug = 'veritas';
        self::$name = 'Veritas University, Abuja';
        self::$short = 'Veritas';
        self::$domain = 'veritas.local';
        self::$hosts = ['localhost', '127.0.0.1'];
        self::$institutionOnly = false;
    }

    public static function useDemo(): void
    {
        self::$slug = 'demo';
        self::$name = 'Demo University';
        self::$short = 'Demo';
        self::$domain = 'demo.invalid'; // a reserved domain: no e-mail can ever be delivered to it
        self::$hosts = [];
        self::$institutionOnly = true;
    }
}
