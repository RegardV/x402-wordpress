<?php
declare(strict_types=1);

namespace X402;

use InvalidArgumentException;

/** Builds the x402 v2 402 challenge — wire shape pinned by tests/fixtures/challenge-live.json. */
final class Challenge
{
    /** CDP facilitator rejects payloads whose embedded description exceeds ~256 chars (undocumented). */
    public const DESCRIPTION_MAX = 250;

    /** USDC per network: contract address + EIP-712 domain (production-verified). */
    private const USDC = [
        'eip155:8453'  => ['asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', 'name' => 'USD Coin', 'version' => '2'],
        'eip155:84532' => ['asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e', 'name' => 'USDC', 'version' => '2'],
    ];

    /**
     * @param array{url:string, description:string, mime_type:string, amount_micro:int, network:string, pay_to:string} $product
     */
    public static function build(array $product): array
    {
        $usdc = self::USDC[$product['network']] ?? null;
        if ($usdc === null) {
            throw new InvalidArgumentException("unsupported network: {$product['network']}");
        }
        return [
            'x402Version' => 2,
            'error'       => 'Payment required',
            'resource'    => [
                'url'         => $product['url'],
                'description' => self::cap_description($product['description']),
                'mimeType'    => $product['mime_type'],
            ],
            'accepts' => [[
                'scheme'            => 'exact',
                'network'           => $product['network'],
                'amount'            => (string) $product['amount_micro'],
                'asset'             => $usdc['asset'],
                'payTo'             => $product['pay_to'],
                'maxTimeoutSeconds' => 300,
                'extra'             => ['name' => $usdc['name'], 'version' => $usdc['version']],
            ]],
        ];
    }

    public static function header(array $product): string
    {
        return base64_encode((string) json_encode(self::build($product), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function cap_description(string $description): string
    {
        if (mb_strlen($description) <= self::DESCRIPTION_MAX) {
            return $description;
        }
        return mb_substr($description, 0, self::DESCRIPTION_MAX - 1) . '…';
    }
}