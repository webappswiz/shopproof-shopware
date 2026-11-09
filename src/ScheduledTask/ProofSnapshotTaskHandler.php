<?php declare(strict_types=1);

namespace Shopproof\Compliance\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopproof\Compliance\ProofLog\ProofSnapshot;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The daily proof log snapshot, then removal of entries past the retention period.
 */
#[AsMessageHandler(handles: ProofSnapshotTask::class)]
final class ProofSnapshotTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ProofSnapshot $snapshot,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->snapshot->run();
    }
}
