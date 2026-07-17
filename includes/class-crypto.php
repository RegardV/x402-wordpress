<?php
declare(strict_types=1);

namespace X402;

/** Secret-at-rest: sodium secretbox keyed from site salt material (best available in stock WP). */
final class Crypto
{
    public static function encrypt(string $plaintext, string $key_material): string
    {
        $key   = sodium_crypto_generichash($key_material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    public static function decrypt(string $box, string $key_material): ?string
    {
        $raw = base64_decode($box, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $key   = sodium_crypto_generichash($key_material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $open  = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        return $open === false ? null : $open;
    }
}