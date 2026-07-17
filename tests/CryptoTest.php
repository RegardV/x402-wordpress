<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\Crypto;

final class CryptoTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $box = Crypto::encrypt('cdp-secret-value', 'site-salt-material');
        $this->assertNotSame('cdp-secret-value', $box);
        $this->assertSame('cdp-secret-value', Crypto::decrypt($box, 'site-salt-material'));
    }

    public function testWrongKeyAndTamperedBoxReturnNull(): void
    {
        $box = Crypto::encrypt('secret', 'key-a');
        $this->assertNull(Crypto::decrypt($box, 'key-b'));
        $tampered = substr($box, 0, -4) . 'AAAA';
        $this->assertNull(Crypto::decrypt($tampered, 'key-a'));
        $this->assertNull(Crypto::decrypt('garbage', 'key-a'));
    }

    public function testEncryptIsNondeterministic(): void
    {
        $this->assertNotSame(Crypto::encrypt('x', 'k'), Crypto::encrypt('x', 'k'));
    }
}