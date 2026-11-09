<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When Shopware marks an order's payment as refunded (the whole amount), its open withdrawals
 * move to "Refund sent". A partial refund changes nothing: it may not cover what is owed, so the
 * clock keeps running and the merchant checks it.
 */
final class RefundSubscriber implements EventSubscriberInterface
{
    public const EVENT = 'state_enter.order_transaction.state.refunded';

    public function __construct(
        private readonly WithdrawalStatus $status,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [self::EVENT => 'onRefunded'];
    }

    public function onRefunded(object $event): void
    {
        $orderId = method_exists($event, 'getOrderId') ? $event->getOrderId() : null;
        if (!\is_string($orderId)) {
            return;
        }
        try {
            $this->status->orderRefunded($orderId);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not mark the withdrawal as refunded: ' . $e->getMessage());
        }
    }
}
