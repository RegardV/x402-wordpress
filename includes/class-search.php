<?php
declare(strict_types=1);

namespace X402;

/**
 * Natural question → MySQL FULLTEXT BOOLEAN MODE string. Bare space-separated
 * terms are OR-ranked (the packager lesson: AND semantics return zero for
 * natural-language questions); trailing * matches word prefixes.
 */
final class Search
{
    public static function boolean_query(string $query): string
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', $query, $m);
        return implode(' ', array_map(static fn (string $t): string => $t . '*', $m[0]));
    }
}