<?php declare(strict_types=1);

namespace Shopproof\Compliance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The order date a shopper gives on the withdrawal form, when the shop asks for it (0.6.0).
 */
class Migration1793836800WithdrawalOrderDate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793836800;
    }

    public function update(Connection $connection): void
    {
        $exists = $connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            ['table' => 'shopproof_withdrawal_request', 'column' => 'order_date']
        );
        if (!is_numeric($exists) || (int) $exists === 0) {
            $connection->executeStatement('ALTER TABLE `shopproof_withdrawal_request` ADD COLUMN `order_date` DATE NULL AFTER `comment`');
        }
    }
}
