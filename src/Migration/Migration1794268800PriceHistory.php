<?php declare(strict_types=1);

namespace Shopproof\Compliance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The price history behind the 30-day lowest price before a reduction (decision 0040): one row
 * per price change of a product or variant, in the default currency, gross and net. Business data
 * only, no personal data.
 */
class Migration1794268800PriceHistory extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1794268800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `shopproof_price_history` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` BINARY(16) NOT NULL,
                `recorded_at` CHAR(20) NOT NULL,
                `gross` VARCHAR(32) NOT NULL,
                `net` VARCHAR(32) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.shopproof_price_history.product` (`product_id`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
