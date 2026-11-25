<?php declare(strict_types=1);

namespace Shopproof\Compliance\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * The daily quiet email check: emails the shop owner only when something needs them.
 */
class QuietEmailTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'shopproof.quiet_email';
    }

    public static function getDefaultInterval(): int
    {
        return 86400;
    }
}
