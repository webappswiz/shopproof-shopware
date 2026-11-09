<?php declare(strict_types=1);

namespace Shopproof\Compliance\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Records the daily proof log snapshot. Runs on the shop's server and contacts no one.
 */
class ProofSnapshotTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'shopproof.proof_snapshot';
    }

    public static function getDefaultInterval(): int
    {
        return 86400;
    }
}
