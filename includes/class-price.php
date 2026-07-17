<?php
declare(strict_types=1);

namespace X402;

use InvalidArgumentException;

/** USD price string → integer micro-USDC. Integer math only — money never touches floats. */
final class Price
{
    public static function toMicro(string $price): int
    {
        $trimmed = ltrim(trim($price), '$');
        if (!preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $trimmed, $m)) {
            throw new InvalidArgumentException("invalid price: $price");
        }
        $micro = ((int) $m[1]) * 1_000_000 + (int) str_pad($m[2] ?? '', 6, '0');
        if ($micro <= 0) {
            throw new InvalidArgumentException("price must be positive: $price");
        }
        return $micro;
    }
}