<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Psr\Log\LoggerInterface;
use Shopproof\Compliance\ProofLog\ProofChain;
use Shopproof\Compliance\ProofLog\ProofLog;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps a record of each request sent through Shopware's own withdrawal button (Shopware 6.7.9 and
 * later). Runs before Shopware's flow sends the emails, and never stops the request: a failure here
 * is logged and counted (the Shopproof page then shows a notice), and the shopper still gets
 * Shopware's confirmation.
 */
final class WithdrawalSubscriber implements EventSubscriberInterface
{
    /** Shopware\Core\Content\RevocationRequest\Event\RevocationRequestEvent::EVENT_NAME, by name so older 6.7 releases still load the plugin. */
    public const EVENT = 'revocation_request.sent';

    /** System config key counting requests that could not be recorded, until the merchant clears it. */
    public const FAILURES = 'ShopproofCompliance.withdrawalFailures';

    public function __construct(
        private readonly WithdrawalStore $store,
        private readonly LoggerInterface $logger,
        private readonly SystemConfigService $config,
        private readonly ProofLog $proofLog,
        private readonly ReturnsWebhook $returns,
        private readonly WithdrawalItems $items,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [self::EVENT => ['onRequest', 100]];
    }

    public function onRequest(object $event): void
    {
        try {
            $form = WithdrawalRecord::formFromEvent($event);
            $record = $form !== null ? WithdrawalRecord::fromForm($form, new \DateTimeImmutable()) : null;
            if ($record === null) {
                return;
            }
            $channel = method_exists($event, 'getSalesChannelId') ? $event->getSalesChannelId() : null;
            $channel = \is_string($channel) ? $channel : null;
            if ($record['items'] !== '') {
                $record['items'] = $this->orderedItems($record, $channel, $event);
            }
            $id = $this->store->add($record, $channel, OrderEmailLine::period($this->config->getInt('ShopproofCompliance.config.withdrawalDays', $channel)));
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not record a withdrawal request: ' . $e->getMessage());
            $this->countFailure();

            return;
        }
        $this->logProof($id);
        try {
            $this->returns->queue($id, $channel);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not queue a withdrawal for the returns tool: ' . $e->getMessage());
        }
    }

    /**
     * The ticked items that are on the order the request names (PickedItems), none when no order
     * fits. If the order cannot be read (or the event carries no context), the lines are kept as
     * sent.
     *
     * @param array{contract_number: string, email: string, items: string} $record
     */
    private function orderedItems(array $record, ?string $channel, object $event): string
    {
        try {
            $order = $this->store->orderFor($record['contract_number'], $record['email'], $channel);
            if ($order === '' || $channel === null) {
                return '';
            }
            $context = method_exists($event, 'getContext') ? $event->getContext() : null;
            if (!$context instanceof Context) {
                return $record['items'];
            }
            $lines = $this->items->items($order, $channel, $context);

            return PickedItems::onlyOrdered($record['items'], array_map(static fn (array $l): array => ['name' => $l['name'], 'quantity' => $l['quantity']], $lines));
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not check the ticked items against the order: ' . $e->getMessage());

            return $record['items'];
        }
    }

    /**
     * The withdrawal's entry in the proof log: reference, time, country and a hash of the record,
     * no personal details. A failure is logged; the record itself is already kept.
     */
    private function logProof(string $id): void
    {
        try {
            $facts = $this->store->proofFacts($id);
            if ($facts !== null && !$this->proofLog->append('withdrawal', ProofChain::withdrawalSummary($facts['record'], $id, $facts['receivedAt'], $facts['country']))) {
                $this->logger->error('Shopproof could not add a withdrawal to the proof log (lock not available).');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not add a withdrawal to the proof log: ' . $e->getMessage());
        }
    }

    private function countFailure(): void
    {
        try {
            $count = $this->config->get(self::FAILURES);
            $this->config->set(self::FAILURES, (\is_int($count) ? $count : 0) + 1);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not count a failed withdrawal record: ' . $e->getMessage());
        }
    }
}
