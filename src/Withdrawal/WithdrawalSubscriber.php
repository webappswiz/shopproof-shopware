<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Psr\Log\LoggerInterface;
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
            $this->store->add($record, \is_string($channel) ? $channel : null);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not record a withdrawal request: ' . $e->getMessage());
            $this->countFailure();
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
