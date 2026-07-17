<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\Chunker;

final class ChunkerTest extends TestCase
{
    public function testSplitsMarkdownByHeadings(): void
    {
        $md = "# Control plane\nThe head office of the cluster.\n\n## Scheduler\nDecides pod placement.\n";
        $this->assertSame([
            ['heading' => 'Control plane', 'text' => 'The head office of the cluster.'],
            ['heading' => 'Scheduler', 'text' => 'Decides pod placement.'],
        ], Chunker::chunk($md));
    }

    public function testPreambleBeforeFirstHeadingKeepsEmptyHeading(): void
    {
        $chunks = Chunker::chunk("intro text\n# First\nbody");
        $this->assertSame(['heading' => '', 'text' => 'intro text'], $chunks[0]);
        $this->assertSame(['heading' => 'First', 'text' => 'body'], $chunks[1]);
    }

    public function testSplitsWordPressHtmlByHeadingTags(): void
    {
        $html = '<h2 class="wp-block-heading">Networking</h2><p>Services route traffic.</p><h3>DNS</h3><p>CoreDNS resolves <em>names</em>.</p>';
        $this->assertSame([
            ['heading' => 'Networking', 'text' => 'Services route traffic.'],
            ['heading' => 'DNS', 'text' => 'CoreDNS resolves names.'],
        ], Chunker::chunk($html));
    }

    public function testStripsTagsAndCollapsesWhitespaceInText(): void
    {
        $chunks = Chunker::chunk("# H\n<p>a   b</p>\n\n\n<div>c</div>");
        $this->assertSame('a b c', $chunks[0]['text']);
    }

    public function testNoHeadingsYieldsSingleChunk(): void
    {
        $this->assertSame([['heading' => '', 'text' => 'just a note']], Chunker::chunk('just a note'));
    }

    public function testEncodedHtmlCannotRematerializeAsLiveTags(): void
    {
        $chunks = Chunker::chunk("# H\nquoting &lt;script&gt;alert(1)&lt;/script&gt; in notes");
        $this->assertStringNotContainsString('<script>', $chunks[0]['text']);
        $this->assertSame('quoting alert(1) in notes', $chunks[0]['text']);
        $headings = Chunker::chunk("# &lt;img src=x onerror=alert(1)&gt;\nbody");
        $this->assertStringNotContainsString('<img', $headings[0]['heading']);
    }

    public function testEmptySectionsAndEmptyInputAreSkipped(): void
    {
        $this->assertSame([], Chunker::chunk(''));
        $chunks = Chunker::chunk("# Empty\n\n# Full\ncontent");
        $this->assertSame([['heading' => 'Full', 'text' => 'content']], $chunks);
    }
}