<?php declare(strict_types=1);

namespace Shopproof\Compliance\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Downloads the rules file once a day.
 */
class RefreshRulesTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'shopproof.refresh_rules';
    }

    public static function getDefaultInterval(): int
    {
        return 86400;
    }
}
