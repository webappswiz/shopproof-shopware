<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one try from the message queue. A failure is logged and never thrown, so the queue does
 * not retry on its own next to Shopproof's own retry schedule.
 */
#[AsMessageHandler(handles: ReturnsWebhookMessage::class)]
final class ReturnsWebhookHandler
{
    public function __construct(
        private readonly ReturnsWebhook $webhook,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReturnsWebhookMessage $message): void
    {
        try {
            $this->webhook->deliver($message->id, $message->attempt);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not send a withdrawal to the returns tool: ' . $e->getMessage());
        }
    }
}
