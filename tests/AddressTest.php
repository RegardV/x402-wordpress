<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\Address;

final class AddressTest extends TestCase
{
    public function testAcceptsValidEvmAddress(): void
    {
        $this->assertTrue(Address::is_valid('0x26EED96B8e61a9123Ff29C54D00fEb452539E33E'));
        $this->assertTrue(Address::is_valid('0x' . str_repeat('a', 40)));
    }

    public function testRejectsEverythingElse(): void
    {
        foreach ([
            '',
            '0x',
            '0x26EED96B8e61a9123Ff29C54D00fEb452539E33',    // 39 hex chars
            '0x26EED96B8e61a9123Ff29C54D00fEb452539E33EF',  // 41 hex chars
            '26EED96B8e61a9123Ff29C54D00fEb452539E33E',     // missing 0x
            '0xZZED96B8e61a9123Ff29C54D00fEb452539E33E',    // non-hex
            ' 0x26EED96B8e61a9123Ff29C54D00fEb452539E33E',  // whitespace
        ] as $bad) {
            $this->assertFalse(Address::is_valid($bad), "should reject: '$bad'");
        }
    }
}