<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\Challenge;
use X402\Price;

final class ChallengeTest extends TestCase
{
    private function product(array $extra = []): array
    {
        return array_merge([
            'url'          => 'https://x402.inkypyrus.com/ask-k8s',
            'description'  => 'Paid retrieval over a Kubernetes knowledge vault that explains the whole system as a company — control plane as head office, pods on the floor, scheduling, scaling, reliability, security. POST {"query": "..."} and get cited passages.',
            'mime_type'    => '',
            'amount_micro' => 20000,
            'network'      => 'eip155:8453',
            'pay_to'       => '0x680D90AdDB54131E946e15cb1757eE98DA4C54DB',
        ], $extra);
    }

    public function testChallengeMatchesLiveProductionFixture(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/challenge-live.json'), true);
        $this->assertSame($fixture, Challenge::build($this->product()));
    }

    public function testHeaderIsBase64OfChallengeJson(): void
    {
        $decoded = json_decode(base64_decode(Challenge::header($this->product()), true), true);
        $this->assertSame(Challenge::build($this->product()), $decoded);
    }

    public function testTestnetUsesSepoliaUsdcAndItsEip712Domain(): void
    {
        $accepts = Challenge::build($this->product(['network' => 'eip155:84532']))['accepts'][0];
        $this->assertSame('0x036CbD53842c5426634e7929541eC2318f3dCF7e', $accepts['asset']);
        $this->assertSame(['name' => 'USDC', 'version' => '2'], $accepts['extra']);
    }

    public function testDescriptionOver250CharsIsCappedForCdpFacilitator(): void
    {
        $long = str_repeat('a', 300);
        $desc = Challenge::build($this->product(['description' => $long]))['resource']['description'];
        $this->assertSame(250, mb_strlen($desc));
        $this->assertSame('…', mb_substr($desc, -1));
    }

    public function testUnknownNetworkIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Challenge::build($this->product(['network' => 'eip155:1']));
    }

    public function testPriceToMicro(): void
    {
        $this->assertSame(20000, Price::toMicro('$0.02'));
        $this->assertSame(20000, Price::toMicro('0.02'));
        $this->assertSame(1000000, Price::toMicro('1'));
        $this->assertSame(1500000, Price::toMicro('1.50'));
        $this->assertSame(10, Price::toMicro('0.00001'));
    }

    public function testPriceRejectsGarbageZeroAndNegative(): void
    {
        foreach (['', 'abc', '-1', '0', '$', '1.2.3'] as $bad) {
            try {
                Price::toMicro($bad);
                $this->fail("expected rejection of '$bad'");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}