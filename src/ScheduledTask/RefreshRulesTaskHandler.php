<?php declare(strict_types=1);

namespace Shopproof\Compliance\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopproof\Compliance\Rules\RulesUpdater;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Daily rules update. Runs only when the merchant turned on "Download rule updates once a day"
 * (off by default), so the plugin contacts no server until the merchant asks for it.
 */
#[AsMessageHandler(handles: RefreshRulesTask::class)]
final class RefreshRulesTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly RulesUpdater $updater,
        private readonly SystemConfigService $config,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public const SETTING = 'ShopproofCompliance.config.autoUpdateRules';

    public function run(): void
    {
        if ($this->config->getBool(self::SETTING)) {
            $this->updater->refresh();
        }
    }
}
