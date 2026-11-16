<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheCookieEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The deposit note shows only to shoppers whose shipping country has the deposit type, so once a
 * shop has deposit types, Shopware's page cache keeps one copy per shipping country. Without this
 * a logged-in shopper from Austria could get a page cached for a German shopper, with the German
 * deposit. Shops without deposit types keep Shopware's cache as it is.
 */
final class DepositCacheSubscriber implements EventSubscriberInterface
{
    public const PART = 'shopproof-shipping-country';

    public function __construct(private readonly NotesSettings $settings)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [HttpCacheCookieEvent::class => 'addCountry'];
    }

    public function addCountry(HttpCacheCookieEvent $event): void
    {
        $context = $event->context;
        if ($this->settings->depositTypes($context->getSalesChannelId())['types'] === []) {
            return;
        }
        $event->add(self::PART, (string) $context->getShippingLocation()->getCountry()->getIso());
    }
}
