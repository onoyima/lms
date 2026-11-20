<?php

namespace App\Support\Exams;

/**
 * How hard the server is working, as a factor the exam room multiplies its check-in and autosave
 * intervals by (1 = normal, up to 4 = very busy). Answers are always kept on the candidate's device
 * first, so checking in less often loses nothing; it just lets a busy server breathe at peak times
 * (everyone starting, everyone submitting). "EXAM_PACE=normal" turns the adaptation off.
 */
class ServerPace
{
    private static ?int $memo = null;

    private static ?float $memoAt = null;

    public static function factor(): int
    {
        if (config('platform.exam_pace', 'auto') !== 'auto') {
            return 1;
        }
        $now = microtime(true);
        if (self::$memo !== null && $now - (float) self::$memoAt < 5) {
            return self::$memo;
        }
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0.0) : 0.0;
        $ratio = $load / max(1, self::cores());
        self::$memoAt = $now;

        return self::$memo = match (true) {
            $ratio < 1.5 => 1,
            $ratio < 2.5 => 2,
            $ratio < 4 => 3,
            default => 4,
        };
    }

    private static ?int $cores = null;

    public static function cores(): int
    {
        if (self::$cores !== null) {
            return self::$cores;
        }
        $n = (int) config('platform.cpu_cores', 0);
        if ($n <= 0 && is_readable('/proc/cpuinfo')) {
            $n = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
        }
        if ($n <= 0) {
            $n = (int) (getenv('NUMBER_OF_PROCESSORS') ?: 1); // Windows
        }

        return self::$cores = max(1, $n);
    }
}
