<?php
declare(strict_types=1);

namespace X402;

use InvalidArgumentException;

/**
 * CDP facilitator auth: Bearer JWT signed EdDSA over CDP's 64-byte base64
 * Ed25519 key (seed+pub — libsodium's native secret-key layout). Shape pinned
 * against @coinbase/cdp-sdk generateJwt and verified by tests.
 */
final class CdpJwt
{
    public const HOST = 'api.cdp.coinbase.com';

    public static function build(string $key_id, string $base64_secret, string $method, string $path, ?int $now = null): string
    {
        $secret = base64_decode($base64_secret, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('CDP key secret must be a base64 64-byte Ed25519 key');
        }
        $now ??= time();

        $header = self::b64url((string) json_encode([
            'alg'   => 'EdDSA',
            'kid'   => $key_id,
            'typ'   => 'JWT',
            'nonce' => bin2hex(random_bytes(16)),
        ]));
        $claims = self::b64url((string) json_encode([
            'sub'  => $key_id,
            'iss'  => 'cdp',
            'uris' => ["$method " . self::HOST . $path],
            'iat'  => $now,
            'nbf'  => $now,
            'exp'  => $now + 120,
        ]));
        $signature = sodium_crypto_sign_detached("$header.$claims", $secret);
        return "$header.$claims." . self::b64url($signature);
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}