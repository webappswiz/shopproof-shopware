<?php declare(strict_types=1);

namespace Shopproof\Compliance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Delivery columns for withdrawal records (0.6.0): whether the record reached the shop's returns
 * tool (queued, sent, retrying, failed), after how many tries, the tool's last HTTP status and when.
 */
class Migration1794009600ReturnsWebhook extends MigrationStep
{
    private const COLUMNS = [
        'webhook_status' => 'VARCHAR(10) NULL AFTER `period_days`',
        'webhook_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `webhook_status`',
        'webhook_code' => 'SMALLINT UNSIGNED NULL AFTER `webhook_attempts`',
        'webhook_at' => 'DATETIME(3) NULL AFTER `webhook_code`',
    ];

    public function getCreationTimestamp(): int
    {
        return 1794009600;
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
