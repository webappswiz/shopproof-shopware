<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The checkout boxes on the server: the order is refused while a chosen box is not ticked (as
 * Shopware does for its own terms box), and the ticked boxes with their words and the time go on
 * the order. Only for orders placed from Shopware's storefront confirmation page, where the boxes
 * are shown; orders from the Store API, the admin or an import are left alone.
 */
final class CheckoutBoxesSubscriber implements EventSubscriberInterface
{
    public const ORDER_ROUTE = 'frontend.checkout.finish.order';

    public function __construct(
        private readonly NotesSettings $settings,
        private readonly NotesTexts $texts,
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly CartService $carts,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'framework.validation.order.create' => 'validate',
            CartConvertedEvent::class => 'record',
        ];
    }

    /**
     * The boxes for a cart, in the shopper's language.
     *
     * @return list<array{key: string, field: string, before: string, link: string, after: string, page: string, text: string}>
     */
    public function boxes(Cart $cart, SalesChannelContext $context): array
    {
        $channel = $context->getSalesChannelId();
        $chosen = $this->settings->checkoutBoxes($channel);
        if ($chosen === []) {
            return [];
        }
        $tags = $this->settings->customBoxTags($channel);

        return CheckoutBoxes::boxes(
            $chosen,
            $this->settings->policyPages($channel),
            $this->settings->customBoxTexts($channel),
            $tags,
            array_filter($tags) === [] ? [] : $this->cartTags($cart),
            $this->texts,
            NotesTexts::language((string) $context->getLanguageInfo()->localeCode, $this->settings->shopCountry($context->getSalesChannelId()))
        );
    }

    public function validate(BuildValidationEvent $event): void
    {
        $request = $this->requests->getCurrentRequest();
        $context = $request?->attributes->get('sw-sales-channel-context');
        if ($request === null || $request->attributes->get('_route') !== self::ORDER_ROUTE || !$context instanceof SalesChannelContext) {
            return;
        }
        // Shopware's order service has just loaded this cart; the cart service keeps it for the request.
        $cart = $this->carts->getCart($context->getToken(), $context);
        foreach ($this->boxes($cart, $context) as $box) {
            $event->getDefinition()->add($box['field'], new NotBlank());
        }
    }

    public function record(CartConvertedEvent $event): void
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null || $request->attributes->get('_route') !== self::ORDER_ROUTE) {
            return;
        }
        $boxes = $this->boxes($event->getCart(), $event->getSalesChannelContext());
        $checks = CheckoutBoxes::record($boxes, $request->request->all(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        if ($checks === []) {
            return;
        }
        $converted = $event->getConvertedCart();
        $fields = \is_array($converted['customFields'] ?? null) ? $converted['customFields'] : [];
        $fields[CheckoutBoxes::ORDER_FIELD] = $checks;
        $fields[CheckoutBoxes::ORDER_TEXT_FIELD] = CheckoutBoxes::recordText($checks);
        $converted['customFields'] = $fields;
        $event->setConvertedCart($converted);
    }

    /**
     * Lower case tag names of the products in the cart (and their main products, for variants).
     *
     * @return list<string>
     */
    private function cartTags(Cart $cart): array
    {
        $ids = [];
        foreach ($cart->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $item) {
            foreach ([$item->getReferencedId(), $item->getPayloadValue('parentId')] as $id) {
                if (\is_string($id) && Uuid::isValid($id)) {
                    $ids[$id] = Uuid::fromHexToBytes($id);
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        $names = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT t.name FROM product_tag pt INNER JOIN tag t ON t.id = pt.tag_id WHERE pt.product_id IN (:ids)',
            ['ids' => array_values($ids)],
            ['ids' => ArrayParameterType::BINARY]
        );

        return array_map(static fn (mixed $name): string => mb_strtolower(trim(\is_string($name) ? $name : '')), $names);
    }
}
