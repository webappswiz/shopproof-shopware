<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records a product's price in the price history each time products are saved in the live
 * version (admin, API, import). Variants that take their parent's price are recorded with it.
 * A failure is logged and never stops the save; the daily sweep catches up.
 */
final class PriceHistorySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PriceHistory $history,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return ['product.written' => 'onWritten'];
    }

    public function onWritten(EntityWrittenEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }
        try {
            $this->history->recordProducts(array_values(array_filter($event->getIds(), \is_string(...))));
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not record a product price: ' . $e->getMessage());
        }
    }
}
