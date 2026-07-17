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
        // Obsidian/Jekyll frontmatter often carries drafts' private metadata — never sell it.
        return (string) preg_replace('/\A---\R.*?\R---\R?/s', '', $content, 1);
    }
}