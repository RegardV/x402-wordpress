<?php
declare(strict_types=1);

namespace X402;

/** Splits post/markdown content into heading-addressed chunks (packager chunking rules). */
final class Chunker
{
    /** @return array<array{heading:string, text:string}> */
    public static function chunk(string $content): array
    {
        // Normalize HTML headings (Gutenberg/classic content) to markdown heading lines.
        $normalized = (string) preg_replace('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', "\n# $1\n", $content);

        $chunks  = [];
        $heading = '';
        $buffer  = [];
        // Decode entities BEFORE stripping tags — the reverse order lets encoded
        // markup (&lt;script&gt;) re-materialize as live tags in stored chunks.
        $flush = function () use (&$chunks, &$heading, &$buffer): void {
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode(implode("\n", $buffer)))));
            if ($text !== '') {
                $chunks[] = ['heading' => $heading, 'text' => $text];
            }
            $buffer = [];
        };

        foreach (preg_split('/\R/', $normalized) ?: [] as $line) {
            if (preg_match('/^#{1,6}\s*(.+)$/', trim($line), $m)) {
                $flush();
                $heading = trim(strip_tags(html_entity_decode($m[1])));
            } else {
                $buffer[] = $line;
            }
        }
        $flush();
        return $chunks;
    }
}