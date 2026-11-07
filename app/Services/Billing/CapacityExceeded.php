<?php

namespace App\Services\Billing;

use RuntimeException;

/** The institution's subscription has no room for this. The message is safe to show to anyone in the institution. */
class CapacityExceeded extends RuntimeException
{
    public function __construct(public readonly string $key, public readonly int $limit, public readonly int|float $used)
    {
        [$label, $unit] = Entitlements::LIMITS[$key] ?? [$key, ''];
        $fmt = fn ($n) => number_format((float) $n, $n == (int) $n ? 0 : 1);
        $message = in_array($key, Entitlements::CEILINGS, true)
            ? "This file is larger than your institution's subscription allows ({$fmt($limit)} MB per file)."
            : "Your institution's subscription allows {$fmt($limit)} ".mb_strtolower($label).($unit && ! in_array($unit, ['accounts', 'people'], true) ? " ($unit)" : '')
                ." and {$fmt($used)} are in use. Ask your administrator to buy extra capacity or upgrade the package.";
        parent::__construct($message);
    }
}
