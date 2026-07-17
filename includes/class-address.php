<?php
declare(strict_types=1);

namespace X402;

/** EVM address shape check — 0x + 40 hex chars. (Receive address only; no keys.) */
final class Address
{
    public static function is_valid(string $address): bool
    {
        return preg_match('/^0x[0-9a-fA-F]{40}$/', $address) === 1;
    }
}