<?php declare(strict_types=1);

namespace Shopproof\Compliance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The shop's proof log (decision 0013): an append-only hash chain of what the shop showed and
 * which withdrawals it received, with no personal details. Same columns as the WooCommerce
 * plugin's table, so both export the same CSV.
 */
class Migration1793750400ProofLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793750400;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `shopproof_proof_log` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `created_at` CHAR(20) NOT NULL,
                `type` VARCHAR(20) NOT NULL,
                `summary` LONGTEXT NOT NULL,
                `prev_hash` CHAR(64) NOT NULL,
                `hash` CHAR(64) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.shopproof_proof_log.created_at` (`created_at`),
                KEY `idx.shopproof_proof_log.type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
