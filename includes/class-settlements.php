<?php
declare(strict_types=1);

namespace X402;

/** Settlement ledger. UNIQUE(tx_hash) is the one-payment-one-delivery idempotency — DB constraint, not app logic. */
final class Settlements
{
    public function __construct(private object $wpdb)
    {
    }

    /** True if this tx was recorded now; false if it was already recorded (duplicate → redeliver, don't recharge). */
    public function record_once(string $product_ref, int $amount_micro, array $settlement): bool
    {
        $inserted = $this->wpdb->insert($this->wpdb->prefix . 'x402_settlements', [
            'tx_hash'           => $settlement['tx'],
            'payer'             => $settlement['payer'],
            'product_ref'       => $product_ref,
            'amount_usdc_micro' => $amount_micro,
            'network'           => $settlement['network'],
            'created_at'        => gmdate('Y-m-d H:i:s'),
        ]);
        return $inserted !== false;
    }

    /** dbDelta-compatible schema. */
    public static function table_sql(string $prefix, string $charset_collate): string
    {
        return "CREATE TABLE {$prefix}x402_settlements (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            tx_hash varchar(80) NOT NULL,
            payer varchar(64) NOT NULL,
            product_ref varchar(191) NOT NULL,
            amount_usdc_micro bigint unsigned NOT NULL,
            network varchar(32) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tx_hash (tx_hash)
        ) $charset_collate;";
    }
}