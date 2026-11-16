<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Shopware\Core\Checkout\Cart\Event\CartBeforeSerializationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Shopware strips a product's custom fields from the stored cart unless each field allows it. The
 * deposit and the cart description are read again every time the cart is calculated, so their
 * fields stay with the cart. Only these three fields; everything else is stripped as usual.
 */
final class CartFieldsSubscriber implements EventSubscriberInterface
{
    public const FIELDS = [Deposit::PREFIX . 'types', Deposit::PREFIX . 'units', NotesCartProcessor::DESCRIPTION_FIELD];

    public static function getSubscribedEvents(): array
    {
        return [CartBeforeSerializationEvent::class => 'keepFields'];
    }

    public function keepFields(CartBeforeSerializationEvent $event): void
    {
        foreach (self::FIELDS as $field) {
            $event->addCustomFieldToAllowList($field);
        }
    }
}
