<?php
declare(strict_types=1);

namespace X402;

/**
 * Facilitator HTTP client — /verify and /settle with the SDK's exact body shape:
 * {x402Version, paymentPayload, paymentRequirements}. Never throws on payment
 * or transport failure; every path returns ['ok' => bool, ...].
 */
final class Facilitator
{
    private string $url;
    /** @var callable(string, array): array{code:int, body:array, error?:string} */
    private $transport;

    public function __construct(string $url, ?callable $transport = null)
    {
        $this->url = rtrim($url, '/');
        $this->transport = $transport ?? [self::class, 'wp_transport'];
    }

    /** Decode an X-PAYMENT header: base64 JSON object with x402Version, verbatim. */
    public static function decode_payment(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }
        $json = base64_decode($header, true);
        if ($json === false) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['x402Version'])) {
            return null;
        }
        return $payload;
    }

    public function verify(array $payment_payload, array $requirements): array
    {
        $response = $this->post('verify', $payment_payload, $requirements);
        if ($response['code'] !== 200 || ($response['body']['isValid'] ?? false) !== true) {
            return [
                'ok'    => false,
                'error' => $response['body']['invalidReason']
                    ?? $response['error']
                    ?? ($response['body']['error'] ?? "facilitator verify failed ({$response['code']})"),
                'raw'   => $response['body'],
            ];
        }
        return ['ok' => true, 'raw' => $response['body']];
    }

    public function settle(array $payment_payload, array $requirements): array
    {
        $response = $this->post('settle', $payment_payload, $requirements);
        $body = $response['body'];
        if ($response['code'] !== 200 || ($body['success'] ?? false) !== true) {
            return [
                'ok'    => false,
                'error' => $body['errorReason'] ?? $response['error'] ?? "facilitator settle failed ({$response['code']})",
                'raw'   => $body,
            ];
        }
        return [
            'ok'      => true,
            'tx'      => $body['transaction'] ?? '',
            'payer'   => $body['payer'] ?? '',
            'network' => $body['network'] ?? '',
            'raw'     => $body,
        ];
    }

    /** PAYMENT-RESPONSE receipt header for the buyer. */
    public static function receipt_header(array $settle_result): string
    {
        return base64_encode((string) json_encode([
            'success'     => $settle_result['ok'],
            'payer'       => $settle_result['payer'],
            'transaction' => $settle_result['tx'],
            'network'     => $settle_result['network'],
        ]));
    }

    private function post(string $endpoint, array $payment_payload, array $requirements): array
    {
        return ($this->transport)("{$this->url}/{$endpoint}", [
            'x402Version'         => $payment_payload['x402Version'],
            'paymentPayload'      => $payment_payload,
            'paymentRequirements' => $requirements,
        ]);
    }

    /** Default transport: WordPress HTTP API. Only reachable inside WP. */
    private static function wp_transport(string $url, array $body): array
    {
        $response = \wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => (string) json_encode($body),
            'timeout' => 30,
        ]);
        if (\is_wp_error($response)) {
            return ['code' => 0, 'body' => [], 'error' => $response->get_error_message()];
        }
        $decoded = json_decode((string) \wp_remote_retrieve_body($response), true);
        return [
            'code' => (int) \wp_remote_retrieve_response_code($response),
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }
}