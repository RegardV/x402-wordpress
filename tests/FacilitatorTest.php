<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\Facilitator;

final class FacilitatorTest extends TestCase
{
    private array $payment;
    private array $requirements;

    protected function setUp(): void
    {
        $this->payment = json_decode((string) file_get_contents(__DIR__ . '/fixtures/payment-payload.json'), true);
        $challenge = json_decode((string) file_get_contents(__DIR__ . '/fixtures/challenge-live.json'), true);
        $this->requirements = $challenge['accepts'][0];
    }

    public function testDecodePaymentRoundTripsRealPayload(): void
    {
        $header = base64_encode((string) json_encode($this->payment));
        $this->assertSame($this->payment, Facilitator::decode_payment($header));
    }

    public function testDecodePaymentRejectsGarbage(): void
    {
        $this->assertNull(Facilitator::decode_payment(null));
        $this->assertNull(Facilitator::decode_payment(''));
        $this->assertNull(Facilitator::decode_payment('not-base64!!'));
        $this->assertNull(Facilitator::decode_payment(base64_encode('"just a string"')));
        $this->assertNull(Facilitator::decode_payment(base64_encode('{"no":"version"}')));
    }

    public function testVerifyPostsSdkShapedBodyToVerifyEndpoint(): void
    {
        $seen = [];
        $f = new Facilitator('https://x402.org/facilitator/', function (string $url, array $body) use (&$seen) {
            $seen = ['url' => $url, 'body' => $body];
            return ['code' => 200, 'body' => ['isValid' => true]];
        });
        $result = $f->verify($this->payment, $this->requirements);
        $this->assertTrue($result['ok']);
        $this->assertSame('https://x402.org/facilitator/verify', $seen['url']);
        $this->assertSame([
            'x402Version'         => 2,
            'paymentPayload'      => $this->payment,
            'paymentRequirements' => $this->requirements,
        ], $seen['body']);
    }

    public function testVerifyInvalidAndTransportErrorAreNotOkAndNeverThrow(): void
    {
        $invalid = new Facilitator('https://f', fn () => ['code' => 200, 'body' => ['isValid' => false, 'invalidReason' => 'bad sig']]);
        $result = $invalid->verify($this->payment, $this->requirements);
        $this->assertFalse($result['ok']);
        $this->assertSame('bad sig', $result['error']);

        $down = new Facilitator('https://f', fn () => ['code' => 0, 'body' => [], 'error' => 'connection refused']);
        $this->assertFalse($down->verify($this->payment, $this->requirements)['ok']);

        $http400 = new Facilitator('https://f', fn () => ['code' => 400, 'body' => ['error' => 'nope']]);
        $this->assertFalse($http400->verify($this->payment, $this->requirements)['ok']);
    }

    public function testSettleReturnsTransactionDetails(): void
    {
        $seen = [];
        $f = new Facilitator('https://f', function (string $url, array $body) use (&$seen) {
            $seen['url'] = $url;
            return ['code' => 200, 'body' => [
                'success' => true, 'transaction' => '0xabc', 'payer' => '0xdd3C', 'network' => 'eip155:8453',
            ]];
        });
        $result = $f->settle($this->payment, $this->requirements);
        $this->assertSame('https://f/settle', $seen['url']);
        $this->assertTrue($result['ok']);
        $this->assertSame('0xabc', $result['tx']);
        $this->assertSame('0xdd3C', $result['payer']);
        $this->assertSame('eip155:8453', $result['network']);
    }

    public function testSettleFailureIsNotOk(): void
    {
        $f = new Facilitator('https://f', fn () => ['code' => 200, 'body' => ['success' => false, 'errorReason' => 'insufficient']]);
        $this->assertFalse($f->settle($this->payment, $this->requirements)['ok']);
    }

    public function testReceiptHeaderEncodesSettlementForBuyer(): void
    {
        $header = Facilitator::receipt_header([
            'ok' => true, 'tx' => '0xabc', 'payer' => '0xdd3C', 'network' => 'eip155:8453',
        ]);
        $this->assertSame(
            ['success' => true, 'payer' => '0xdd3C', 'transaction' => '0xabc', 'network' => 'eip155:8453'],
            json_decode(base64_decode($header, true), true)
        );
    }
}