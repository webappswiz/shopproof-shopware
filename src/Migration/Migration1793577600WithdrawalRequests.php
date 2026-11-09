<?php declare(strict_types=1);

namespace Shopproof\Compliance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * A record of every withdrawal sent through Shopware's own withdrawal button, so the shop can show
 * when it received each one. Shopware itself only sends the emails and keeps nothing.
 */
class Migration1793577600WithdrawalRequests extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793577600;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `shopproof_withdrawal_request` (
                `id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NULL,
                `first_name` VARCHAR(255) NOT NULL DEFAULT '',
                `last_name` VARCHAR(255) NOT NULL DEFAULT '',
                `email` VARCHAR(320) NOT NULL,
                `contract_number` VARCHAR(255) NOT NULL,
                `comment` TEXT NULL,
                `order_id` BINARY(16) NULL,
                `submitted_at` DATETIME(3) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.shopproof_withdrawal_request.submitted_at` (`submitted_at`),
                KEY `idx.shopproof_withdrawal_request.email` (`email`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
