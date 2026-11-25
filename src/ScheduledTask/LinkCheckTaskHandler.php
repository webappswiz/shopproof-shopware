<?php declare(strict_types=1);

namespace Shopproof\Compliance\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopproof\Compliance\Placement\WeeklyLinkCheck;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The weekly link check.
 */
#[AsMessageHandler(handles: LinkCheckTask::class)]
final class LinkCheckTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly WeeklyLinkCheck $check,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->check->run();
    }
}
