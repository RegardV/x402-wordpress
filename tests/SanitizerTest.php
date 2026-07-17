<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\Sanitizer;

final class SanitizerTest extends TestCase
{
    public function testAcceptsMarkdownAndTextPathsIncludingNested(): void
    {
        $this->assertNull(Sanitizer::check_name('notes.md'));
        $this->assertNull(Sanitizer::check_name('vault/10-k8s/Control plane.md'));
        $this->assertNull(Sanitizer::check_name('README.TXT'));
        $this->assertNull(Sanitizer::check_name('a.markdown'));
    }

    public function testRejectsDotfilesTraversalAndWrongExtensions(): void
    {
        foreach ([
            '.env'                   => 'dotfile',
            'vault/.obsidian/app.md' => 'dot-directory',
            '../escape.md'           => 'traversal',
            'a/../../b.md'           => 'traversal',
            'report.pdf'             => 'extension',
            'binary.exe'             => 'extension',
            'noextension'            => 'extension',
        ] as $path => $why) {
            $this->assertIsString(Sanitizer::check_name($path), "should reject ($why): $path");
        }
    }

    public function testStripsYamlFrontmatter(): void
    {
        $in = "---\ntags: [secret-draft]\napi_hint: xyz\n---\n# Real content\nbody";
        $this->assertSame("# Real content\nbody", Sanitizer::clean($in));
    }

    public function testContentWithoutFrontmatterIsUnchanged(): void
    {
        $this->assertSame("# T\nbody", Sanitizer::clean("# T\nbody"));
    }

    public function testRejectsBinaryInvalidUtf8AndKeyMaterial(): void
    {
        $this->assertNull(Sanitizer::clean("has\x00null"));
        $this->assertNull(Sanitizer::clean("bad utf8: \xC3\x28"));
        $this->assertNull(Sanitizer::clean("notes\n-----BEGIN RSA PRIVATE KEY-----\nabc"));
        $this->assertNull(Sanitizer::clean("-----BEGIN OPENSSH PRIVATE KEY-----"));
    }
}