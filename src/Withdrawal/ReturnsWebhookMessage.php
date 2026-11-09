<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * One try at sending a withdrawal to the returns tool (ReturnsWebhook), run by the message queue.
 */
final class ReturnsWebhookMessage implements AsyncMessageInterface
{
    public function __construct(
        public readonly string $id,
        public readonly int $attempt,
    ) {
    }
}
