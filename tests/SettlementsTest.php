<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use X402\Settlements;

/** Minimal wpdb stand-in: insert() honoring a UNIQUE(tx_hash) like MySQL would. */
final class FakeWpdb
{
    public string $prefix = 'wp_';
    public array $rows = [];
    public function insert(string $table, array $data): int|false
    {
        foreach ($this->rows as $row) {
            if ($row['tx_hash'] === $data['tx_hash']) {
                return false;
            }
        }
        $this->rows[] = $data;
        return 1;
    }
}

final class SettlementsTest extends TestCase
{
    public function testFirstRecordIsNewDuplicateTxIsFlagged(): void
    {
        $s = new Settlements(new FakeWpdb());
        $settlement = ['tx' => '0xabc', 'payer' => '0xdd3C', 'network' => 'eip155:84532'];
        $this->assertTrue($s->record_once('demo', 20000, $settlement));
        $this->assertFalse($s->record_once('demo', 20000, $settlement), 'same tx must not record twice');
    }

    public function testTableSqlHasUniqueTxHashConstraint(): void
    {
        $sql = Settlements::table_sql('wp_', 'DEFAULT CHARACTER SET utf8mb4');
        $this->assertStringContainsString('wp_x402_settlements', $sql);
        $this->assertStringContainsString('UNIQUE KEY tx_hash (tx_hash)', $sql);
        $this->assertStringContainsString('amount_usdc_micro bigint', $sql);
    }
}