<?php
declare(strict_types=1);

namespace X402;

/** Ask-collection helpers. slug() is pure; registry read/write lives in indexer.php (WP options). */
final class Collections
{
    /** Any human name → a URL-safe collection slug (the {slug} in /x402/v1/ask/{slug}). */
    public static function slug(string $input): string
    {
        $s = strtolower(trim($input));
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        return substr($s, 0, 64);
    }
}