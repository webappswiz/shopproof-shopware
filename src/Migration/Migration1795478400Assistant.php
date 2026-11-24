<?php declare(strict_types=1);

namespace Shopproof\Compliance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The AI assistant connection (decision 0047): connection keys (SHA-256 hash only), preview codes
 * for 30 minutes and the last 100 changes made through an assistant. Holds the admin user id of
 * each key and change, and setting values; no customer data.
 */
class Migration1795478400Assistant extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1795478400;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `shopproof_assistant` (
                `kind` VARCHAR(8) NOT NULL,
                `code` VARCHAR(32) NOT NULL,
                `user_id` BINARY(16) NULL,
                `data` LONGTEXT NOT NULL,
                `created_at` DATETIME NOT NULL,
                `expires_at` DATETIME NULL,
                `used_at` DATETIME NULL,
                `undone_at` DATETIME NULL,
                PRIMARY KEY (`kind`, `code`),
                KEY `idx.shopproof_assistant.created` (`kind`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
