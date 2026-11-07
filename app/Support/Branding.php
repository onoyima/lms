<?php

namespace App\Support;

class Branding
{
    /**
     * Pick near-black or white text for a background colour, using WCAG relative
     * luminance, so any institution colour stays readable on buttons.
     */
    public static function readableTextOn(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $lum = 0.2126 * self::channel($r) + 0.7152 * self::channel($g) + 0.0722 * self::channel($b);
        $contrastWhite = 1.05 / ($lum + 0.05);
        $contrastDark = ($lum + 0.05) / 0.05;

        return $contrastDark >= $contrastWhite ? '#0b0d10' : '#ffffff';
    }

    public static function isValidHex(string $hex): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $hex);
    }

    /** @return array{int,int,int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::isValidHex($hex) ? $hex : '#10b981', '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function channel(int $v): float
    {
        $c = $v / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
}
