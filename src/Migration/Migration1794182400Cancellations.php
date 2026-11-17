<?php declare(strict_types=1);

namespace Shopproof\Compliance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Cancellations sent on the cancel page (0.8.1): what the customer entered, when it arrived,
 * whether the two emails went out and when the shop marked it done.
 */
class Migration1794182400Cancellations extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794182400;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `shopproof_cancellation` (
                `id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NULL,
                `reference` VARCHAR(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                `email` VARCHAR(320) NOT NULL,
                `contract` VARCHAR(255) NOT NULL DEFAULT '',
                `cancel_type` VARCHAR(16) NOT NULL DEFAULT 'ordinary',
                `reason` TEXT NULL,
                `end_mode` VARCHAR(16) NOT NULL DEFAULT 'earliest',
                `end_date` DATE NULL,
                `lang` VARCHAR(8) NOT NULL DEFAULT 'en',
                `fingerprint` CHAR(64) NOT NULL,
                `receipt_sent` TINYINT(1) NOT NULL DEFAULT 0,
                `shop_copy_sent` TINYINT(1) NOT NULL DEFAULT 0,
                `received_at` DATETIME(3) NOT NULL,
                `done_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.shopproof_cancellation.received_at` (`received_at`),
                KEY `idx.shopproof_cancellation.fingerprint` (`fingerprint`),
                KEY `idx.shopproof_cancellation.email` (`email`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
