<?php
declare(strict_types=1);

namespace X402;

/** Setup-wizard validation — pure, so the guided flow's rules are testable without WordPress. */
final class Setup
{
    /** Null if the entered config is valid, else a human-readable reason. */
    public static function validate(bool $is_mainnet, string $address, string $key_id, string $secret, bool $secret_stored): ?string
    {
        if (!Address::is_valid(trim($address))) {
            return 'Enter a valid wallet address — 0x followed by 40 hex characters.';
        }
        if ($is_mainnet) {
            if (trim($key_id) === '') {
                return 'Mainnet needs your Coinbase CDP API key ID.';
            }
            if (trim($secret) === '' && !$secret_stored) {
                return 'Mainnet needs your Coinbase CDP API key secret.';
            }
        }
        return null;
    }
}