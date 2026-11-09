<?php declare(strict_types=1);

namespace Shopproof\Compliance\Cache;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\DataAbstractionLayer\Cache\EntityCacheKeyGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The product safety block and the durability label show manufacturer details. Shopware clears only
 * product listings from the page cache when a manufacturer changes, so product pages could show the
 * old details until the cache expires. This clears the pages of the manufacturer's products and their
 * variants, through Shopware's own (possibly delayed) cache clearing.
 */
final class ManufacturerCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInvalidator $cacheInvalidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return ['product_manufacturer.written' => 'onWritten'];
    }

    public function onWritten(EntityWrittenEvent $event): void
    {
        try {
            $ids = array_values(array_filter($event->getIds(), \is_string(...)));
            if ($ids === []) {
                return;
            }
            $products = $this->connection->fetchFirstColumn(
                'SELECT LOWER(HEX(product.id)) FROM product
                 LEFT JOIN product parent ON parent.id = product.parent_id AND parent.version_id = product.version_id
                 WHERE product.version_id = :version
                   AND (product.product_manufacturer_id IN (:ids) OR (product.product_manufacturer_id IS NULL AND parent.product_manufacturer_id IN (:ids)))',
                ['ids' => Uuid::fromHexToBytesList($ids), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
                ['ids' => ArrayParameterType::BINARY]
            );
            $tags = array_map(
                static fn (mixed $id): string => EntityCacheKeyGenerator::buildProductTag(\is_string($id) ? $id : ''),
                $products
            );
            if ($tags !== []) {
                $this->cacheInvalidator->invalidate($tags);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not clear product pages after a manufacturer change: ' . $e->getMessage());
        }
    }
}
