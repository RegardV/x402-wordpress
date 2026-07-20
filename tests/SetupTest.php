<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\Setup;

final class SetupTest extends TestCase
{
    private const ADDR = '0x26EED96B8e61a9123Ff29C54D00fEb452539E33E';

    public function testTestnetNeedsOnlyAValidAddress(): void
    {
        $this->assertNull(Setup::validate(false, self::ADDR, '', '', false));
    }

    public function testInvalidAddressIsRejectedOnEitherNetwork(): void
    {
        $this->assertNotNull(Setup::validate(false, 'nope', '', '', false));
        $this->assertNotNull(Setup::validate(true, '0x123', 'key', 'secret', false));
    }

    public function testMainnetNeedsKeyIdAndSecret(): void
    {
        $this->assertNull(Setup::validate(true, self::ADDR, 'key-id', 'the-secret', false));
        $this->assertNotNull(Setup::validate(true, self::ADDR, '', 'the-secret', false));
        $this->assertNotNull(Setup::validate(true, self::ADDR, 'key-id', '', false));
    }

    public function testMainnetAcceptsBlankSecretWhenOneIsAlreadyStored(): void
    {
        // Switching back to mainnet must not force re-entering the stored secret.
        $this->assertNull(Setup::validate(true, self::ADDR, 'key-id', '', true));
    }
}