<?php

namespace App\Services\Media;

/**
 * Browsers write WebM recordings without a length, so players cannot jump around in them.
 * This adds the Duration element to the Segment Info at the start of the file (no other tools
 * needed). If the file is laid out in a way this does not expect, it is returned unchanged.
 */
class WebmDuration
{
    private const EBML = 0x1A45DFA3;

    private const SEGMENT = 0x18538067;

    private const SEEK_HEAD = 0x114D9B74;

    private const INFO = 0x1549A966;

    private const DURATION = 0x4489;

    public static function write(string $bytes, float $ms): string
    {
        try {
            $pos = 0;
            [$id, $pos] = self::id($bytes, $pos);
            if ($id !== self::EBML) {
                return $bytes;
            }
            [$size, $pos] = self::size($bytes, $pos);
            $pos += $size;

            [$id, $segIdEnd] = self::id($bytes, $pos);
            if ($id !== self::SEGMENT) {
                return $bytes;
            }
            $segSizeStart = $segIdEnd;
            [$segSize, $pos, $segUnknown] = self::size($bytes, $segSizeStart, true);
            $segSizeEnd = $pos;

            // look through the first elements of the segment for Info
            for ($guard = 0; $guard < 16 && $pos < strlen($bytes); $guard++) {
                [$id, $p] = self::id($bytes, $pos);
                [$size, $contentStart, $unknown] = self::size($bytes, $p, true);
                if ($unknown) {
                    return $bytes; // reached a cluster of unknown size before Info
                }
                if ($id === self::SEEK_HEAD) {
                    return $bytes; // offsets would shift; leave such files alone
                }
                if ($id === self::INFO) {
                    $content = substr($bytes, $contentStart, $size);
                    if (self::hasChild($content, self::DURATION)) {
                        return $bytes;
                    }
                    $content .= "\x44\x89\x88".pack('E', $ms);
                    $newSize = self::eightByteSize(strlen($content));
                    $delta = strlen($newSize) + strlen($content) - (($contentStart - $p) + $size);
                    $head = substr($bytes, 0, $segSizeStart);
                    $segSizeBytes = $segUnknown ? substr($bytes, $segSizeStart, $segSizeEnd - $segSizeStart) : self::eightByteSize($segSize + $delta);

                    return $head.$segSizeBytes.substr($bytes, $segSizeEnd, $p - $segSizeEnd).$newSize.$content.substr($bytes, $contentStart + $size);
                }
                $pos = $contentStart + $size;
            }

            return $bytes;
        } catch (\Throwable) {
            return $bytes;
        }
    }

    /** Element ID: 1–4 bytes, marker bits kept. */
    private static function id(string $b, int $pos): array
    {
        $first = ord($b[$pos]);
        $len = $first >= 0x80 ? 1 : ($first >= 0x40 ? 2 : ($first >= 0x20 ? 3 : ($first >= 0x10 ? 4 : 0)));
        if ($len === 0) {
            throw new \RuntimeException('bad id');
        }
        $v = 0;
        for ($i = 0; $i < $len; $i++) {
            $v = ($v << 8) | ord($b[$pos + $i]);
        }

        return [$v, $pos + $len];
    }

    /** Element size: 1–8 bytes, marker bit removed; all ones = unknown size. */
    private static function size(string $b, int $pos, bool $withUnknown = false): array
    {
        $first = ord($b[$pos]);
        $len = 1;
        while ($len <= 8 && ! ($first & (0x80 >> ($len - 1)))) {
            $len++;
        }
        if ($len > 8) {
            throw new \RuntimeException('bad size');
        }
        $v = $first & (0xFF >> $len);
        $allOnes = $v === (0xFF >> $len);
        for ($i = 1; $i < $len; $i++) {
            $byte = ord($b[$pos + $i]);
            $allOnes = $allOnes && $byte === 0xFF;
            $v = ($v << 8) | $byte;
        }

        return $withUnknown ? [$v, $pos + $len, $allOnes] : [$v, $pos + $len];
    }

    private static function hasChild(string $content, int $want): bool
    {
        $pos = 0;
        while ($pos < strlen($content)) {
            [$id, $p] = self::id($content, $pos);
            [$size, $p] = self::size($content, $p);
            if ($id === $want) {
                return true;
            }
            $pos = $p + $size;
        }

        return false;
    }

    private static function eightByteSize(int $n): string
    {
        return "\x01".substr(pack('J', $n), 1);
    }
}
