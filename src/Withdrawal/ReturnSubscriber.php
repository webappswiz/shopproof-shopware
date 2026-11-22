<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When Shopware marks an order's delivery as returned, its withdrawals that are still at
 * "Received" move to "Return arrived" and the customer gets the status email (gap audit
 * 22 Nov 2026, gap 8). Only a full return counts: "returned partially", or an order with another
 * delivery not yet back, changes nothing, because the refund may wait until all goods are back
 * (Art. 13(3) Directive 2011/83/EU). A withdrawal already at "Refund sent" stays there.
 */
final class ReturnSubscriber implements EventSubscriberInterface
{
    public const EVENT = 'state_enter.order_delivery.state.returned';

    public function __construct(
        private readonly WithdrawalStatus $status,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [self::EVENT => 'onReturned'];
    }

    public function onReturned(object $event): void
    {
        $orderId = method_exists($event, 'getOrderId') ? $event->getOrderId() : null;
        if (!\is_string($orderId) || preg_match('/^[0-9a-f]{32}$/i', $orderId) !== 1) {
            return;
        }
        try {
            $this->status->orderReturned($orderId);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not mark the return as arrived: ' . $e->getMessage());
        }
    }

    /**
     * Whether every delivery of the order is back: at least one returned, and the others returned
     * or cancelled (a cancelled delivery never left).
     *
     * @param list<string> $states technical names of the order's delivery states
     */
    public static function allBack(array $states): bool
    {
        if (!\in_array('returned', $states, true)) {
            return false;
        }
        foreach ($states as $state) {
            if ($state !== 'returned' && $state !== 'cancelled') {
                return false;
            }
        }

        return true;
    }
}
