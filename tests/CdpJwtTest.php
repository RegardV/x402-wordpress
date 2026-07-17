<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\CdpJwt;

final class CdpJwtTest extends TestCase
{
    private string $secret_b64;
    private string $public_key;

    protected function setUp(): void
    {
        $keypair          = sodium_crypto_sign_seed_keypair(str_repeat("\x42", 32));
        $this->secret_b64 = base64_encode(sodium_crypto_sign_secretkey($keypair)); // 64 bytes: seed+pub, CDP's format
        $this->public_key = sodium_crypto_sign_publickey($keypair);
    }

    private static function b64url_decode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }

    public function testJwtShapeMatchesCdpSdk(): void
    {
        $jwt = CdpJwt::build('my-key-id', $this->secret_b64, 'POST', '/platform/v2/x402/verify', 1700000000);
        [$h, $c, $sig] = explode('.', $jwt);

        $header = json_decode(self::b64url_decode($h), true);
        $this->assertSame('EdDSA', $header['alg']);
        $this->assertSame('my-key-id', $header['kid']);
        $this->assertSame('JWT', $header['typ']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $header['nonce']);

        $claims = json_decode(self::b64url_decode($c), true);
        $this->assertSame('my-key-id', $claims['sub']);
        $this->assertSame('cdp', $claims['iss']);
        $this->assertSame(['POST api.cdp.coinbase.com/platform/v2/x402/verify'], $claims['uris']);
        $this->assertSame(1700000000, $claims['iat']);
        $this->assertSame(1700000000, $claims['nbf']);
        $this->assertSame(1700000120, $claims['exp']);
    }

    public function testSignatureVerifiesAgainstThePublicKey(): void
    {
        $jwt = CdpJwt::build('k', $this->secret_b64, 'GET', '/platform/v2/x402/supported', 1700000000);
        [$h, $c, $sig] = explode('.', $jwt);
        $this->assertTrue(sodium_crypto_sign_verify_detached(self::b64url_decode($sig), "$h.$c", $this->public_key));
    }

    public function testRejectsMalformedSecrets(): void
    {
        foreach (['', 'not-base64!!', base64_encode('too short')] as $bad) {
            try {
                CdpJwt::build('k', $bad, 'POST', '/x', 1700000000);
                $this->fail('expected rejection');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}