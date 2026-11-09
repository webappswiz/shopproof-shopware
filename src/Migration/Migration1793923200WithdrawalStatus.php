<?php declare(strict_types=1);

namespace Shopproof\Compliance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Status and clock columns for withdrawal records (0.6.0): where the withdrawal stands (received,
 * return arrived, refund sent) and when, the delivery date the merchant entered, and the
 * withdrawal period that applied when the request arrived.
 */
class Migration1793923200WithdrawalStatus extends MigrationStep
{
    private const COLUMNS = [
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'received' AFTER `order_date`",
        'return_arrived_at' => 'DATETIME(3) NULL AFTER `status`',
        'refund_sent_at' => 'DATETIME(3) NULL AFTER `return_arrived_at`',
        'delivered_on' => 'DATE NULL AFTER `refund_sent_at`',
        'period_days' => 'SMALLINT UNSIGNED NULL AFTER `delivered_on`',
    ];

    public function getCreationTimestamp(): int
    {
        return 1793923200;
    }

    public function update(Connection $connection): void
    {
        $existing = $connection->fetchFirstColumn(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => 'shopproof_withdrawal_request']
        );
        foreach (self::COLUMNS as $column => $definition) {
            if (!\in_array($column, $existing, true)) {
                $connection->executeStatement(\sprintf('ALTER TABLE `shopproof_withdrawal_request` ADD COLUMN `%s` %s', $column, $definition));
            }
        }
    }
}
