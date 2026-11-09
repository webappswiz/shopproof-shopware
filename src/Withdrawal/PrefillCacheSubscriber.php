<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A page opened from the signed withdrawal link (spOrder and spToken) shows the customer's name,
 * email, order number and order date in the form, so it must never be kept in Shopware's page
 * cache or in a reverse proxy: the answer is private and no-store. This runs after Shopware sets
 * its own cache headers, so nothing later makes the page public again. Any well-formed pair of
 * the two parameters counts, valid or not, so no lookup is needed here.
 */
final class PrefillCacheSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -2000]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        self::apply($event->getRequest()->query->all(), $event->getResponse());
    }

    /**
     * @param array<mixed> $query
     */
    public static function apply(array $query, Response $response): bool
    {
        if (OrderLink::fromQuery($query) === null) {
            return false;
        }
        $response->headers->remove('Cache-Control');
        $response->headers->remove('Expires');
        $response->headers->remove('sw-invalidation-states');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setMaxAge(0);

        return true;
    }
}
