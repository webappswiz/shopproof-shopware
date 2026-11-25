<?php declare(strict_types=1);

namespace Shopproof\Compliance\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * The weekly link check: looks for the withdrawal link on the shop's own storefront pages.
 */
class LinkCheckTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'shopproof.link_check';
    }

    public static function getDefaultInterval(): int
    {
        return 604800;
    }
}
