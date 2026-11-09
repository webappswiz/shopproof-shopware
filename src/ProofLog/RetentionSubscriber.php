<?php declare(strict_types=1);

namespace Shopproof\Compliance\ProofLog;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Writes a "retention" entry into the proof log whenever the merchant changes how long the log
 * keeps entries, so a shorter period is never hidden.
 */
final class RetentionSubscriber implements EventSubscriberInterface
{
    public const KEY = 'ShopproofCompliance.config.proofLogRetentionDays';

    public function __construct(
        private readonly ProofLog $log,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [SystemConfigChangedEvent::class => 'onChange'];
    }

    public function onChange(SystemConfigChangedEvent $event): void
    {
        if ($event->getKey() !== self::KEY) {
            return;
        }
        $value = $event->getValue();
        try {
            // The plugin writes its default settings on install before its tables exist, and
            // uninstall removes the settings after the table; neither is a merchant's change.
            if (!$this->log->exists()) {
                return;
            }
            // The period the pruning applies (at least 30 days, 0 for all), not the typed value, and
            // only when it changed: the settings page saves every setting on Save.
            $entry = [
                'retention_days' => ProofLog::retentionFrom($value),
                'sales_channel' => $event->getSalesChannelId() ?? 'all',
            ];
            $last = $this->log->lastSummary('retention');
            if ($last !== null && ($last['retention_days'] ?? null) === $entry['retention_days'] && ($last['sales_channel'] ?? null) === $entry['sales_channel']) {
                return;
            }
            $this->log->append('retention', $entry);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not record a retention change in the proof log: ' . $e->getMessage());
        }
    }
}
