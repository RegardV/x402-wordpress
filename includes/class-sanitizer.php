<?php
declare(strict_types=1);

namespace X402;

/** Corpus-import gatekeeper (packager rules): what may enter the index, and cleaned how. */
final class Sanitizer
{
    private const EXTENSIONS = ['md', 'markdown', 'txt'];

    /** Null if the archive path is acceptable, otherwise a human-readable rejection reason. */
    public static function check_name(string $path): ?string
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                return 'path traversal';
            }
            if ($segment !== '' && $segment[0] === '.') {
                return 'hidden file or directory';
            }
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXTENSIONS, true)) {
            return 'unsupported type (md, markdown, txt only)';
        }
        return null;
    }

    /** Cleaned content, or null if the file must be rejected (binary or key material). */
    public static function clean(string $content): ?string
    {
        if (str_contains($content, "\x00") || !mb_check_encoding($content, 'UTF-8')) {
            return null;
        }
        if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $content)) {
            return null;
        }
        // Inline credential tokens: a corpus is sold to anyone with pocket change —
        // a leaked key in a note becomes a leaked key for two cents.
        if (preg_match('/AKIA[0-9A-Z]{16}|\bsk-[A-Za-z0-9_-]{20,}|\bghp_[A-Za-z0-9]{36}|\bxox[baprs]-[A-Za-z0-9-]{10,}/', $content)) {
            return null;
        }
        // Obsidian/Jekyll frontmatter often carries drafts' private metadata — never sell it.
        return (string) preg_replace('/\A---\R.*?\R---\R?/s', '', $content, 1);
    }

    /** Upstream-supplied Content-Type → safe header value (or a neutral fallback). */
    public static function safe_content_type(string $value): string
    {
        if (preg_match('#^[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+(;[ -~]*)?$#', $value)) {
            return $value;
        }
        return 'application/octet-stream';
    }
}