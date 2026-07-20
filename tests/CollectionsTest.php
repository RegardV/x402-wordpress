<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\Collections;

final class CollectionsTest extends TestCase
{
    public function testSlugifiesNames(): void
    {
        $this->assertSame('kubernetes-vault', Collections::slug('Kubernetes Vault'));
        $this->assertSame('k8s-notes', Collections::slug('K8s   Notes!'));
        $this->assertSame('foo', Collections::slug('  --Foo--  '));
        $this->assertSame('terraform', Collections::slug('TERRAFORM'));
    }

    public function testStripsNonAsciiAndCollapsesSeparators(): void
    {
        // Non-ASCII collapses to a separator (no transliteration — slugs are internal ids).
        $this->assertSame('caf-menu', Collections::slug('café  menu'));
        $this->assertSame('a-b-c', Collections::slug('a/b\\c'));
    }

    public function testEmptyOrPunctuationOnlyBecomesEmpty(): void
    {
        $this->assertSame('', Collections::slug(''));
        $this->assertSame('', Collections::slug('   '));
        $this->assertSame('', Collections::slug('!!!'));
    }

    public function testCapsLength(): void
    {
        $this->assertSame(64, strlen(Collections::slug(str_repeat('a', 100))));
    }
}