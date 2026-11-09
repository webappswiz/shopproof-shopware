<?php declare(strict_types=1);

namespace Shopproof\Compliance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The items the customer ticked on the withdrawal form (0.6.0), as lines of text ("2 x Shirt"),
 * empty for the whole order.
 */
class Migration1794096000WithdrawalItems extends MigrationStep
{
    private const COLUMNS = [
        'items' => 'TEXT NULL AFTER `comment`',
    ];

    public function getCreationTimestamp(): int
    {
        return 1794096000;
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
