<?php declare(strict_types=1);

namespace Shopproof\Compliance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Keeps the last good copy of the rules file. A table, not system config, because system config is
 * loaded on every request and the file is about 100 KB.
 */
class Migration1793491200RulesCache extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793491200;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `shopproof_rules_cache` (
                `name` VARCHAR(32) NOT NULL,
                `version` VARCHAR(32) NOT NULL,
                `body` LONGTEXT NOT NULL,
                `fetched_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
