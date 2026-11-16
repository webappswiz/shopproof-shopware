<?php declare(strict_types=1);

namespace Shopproof\Compliance\Twig;

use Shopproof\Compliance\Notes\CheckoutBoxesSubscriber;
use Shopproof\Compliance\Notes\Deposit;
use Shopproof\Compliance\Notes\FoodInformation;
use Shopproof\Compliance\Notes\NotesCartProcessor;
use Shopproof\Compliance\Notes\NotesSettings;
use Shopproof\Compliance\Notes\NotesTexts;
use Shopproof\Compliance\Notes\PriceNotes;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Storefront helpers for the shop notes that came over from the Shopify app (owner decision,
 * 8 Nov 2026, "a feature is done only when it is on all free versions"): food information, price
 * notes, deposit, cart descriptions and the checkout boxes. Everything is rendered on the server
 * and takes the theme's own styles; values are plain text that the templates escape.
 */
final class NotesExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotesSettings $settings,
        private readonly NotesTexts $texts,
        private readonly CurrencyFormatter $currency,
        private readonly CheckoutBoxesSubscriber $boxes,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shopproof_food', $this->food(...)),
            new TwigFunction('shopproof_price_tax_line', $this->taxLine(...)),
            new TwigFunction('shopproof_defects', $this->defects(...)),
            new TwigFunction('shopproof_deposit_note', $this->depositNote(...)),
            new TwigFunction('shopproof_included_deposit', $this->includedDeposit(...)),
            new TwigFunction('shopproof_cart_description', $this->cartDescription(...)),
            new TwigFunction('shopproof_checkout_boxes', $this->checkoutBoxes(...)),
            new TwigFunction('shopproof_check_error', $this->checkError(...)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function food(mixed $product, mixed $context): ?array
    {
        if (!$product instanceof ProductEntity || !$context instanceof SalesChannelContext) {
            return null;
        }
        $fields = $product->getTranslation('customFields');

        return FoodInformation::block(
            FoodInformation::values(\is_array($fields) ? $fields : null),
            $this->settings->string('foodBusiness', $context->getSalesChannelId()),
            $this->texts,
            $this->lang($context)
        );
    }

    /**
     * The words for Shopware's tax line on a product, or null to keep Shopware's own.
     */
    public function taxLine(mixed $product, mixed $context): ?string
    {
        if (!$context instanceof SalesChannelContext) {
            return null;
        }
        $channel = $context->getSalesChannelId();
        $fields = $product instanceof ProductEntity ? $product->getTranslation('customFields') : null;

        return PriceNotes::taxLine(
            $this->settings->priceTax($channel),
            $this->settings->priceShipping($channel),
            $this->settings->string('smallBusinessText', $channel),
            \is_array($fields) ? $fields : null,
            $context->getTaxState() === 'gross',
            $this->texts,
            $this->lang($context)
        );
    }

    /**
     * @return array{label: string, text: string}|null
     */
    public function defects(mixed $product, mixed $context): ?array
    {
        if (!$product instanceof ProductEntity || !$context instanceof SalesChannelContext) {
            return null;
        }
        $fields = $product->getTranslation('customFields');

        return PriceNotes::defects(\is_array($fields) ? $fields : null, $this->texts, $this->lang($context));
    }

    /**
     * "plus EUR 0.25 deposit (single-use)" below the price, or '' when the product has no deposit
     * in the shopper's shipping country.
     */
    public function depositNote(mixed $product, mixed $context): string
    {
        if (!$product instanceof ProductEntity || !$context instanceof SalesChannelContext) {
            return '';
        }
        $channel = $context->getSalesChannelId();
        $types = $this->settings->depositTypes($channel)['types'];
        if ($types === []) {
            return '';
        }
        $fields = $product->getTranslation('customFields');
        $deposit = Deposit::forProduct(\is_array($fields) ? $fields : null, $types, (string) $context->getShippingLocation()->getCountry()->getIso());
        $factor = $context->getCurrency()->getFactor();

        return Deposit::note(
            $deposit,
            $this->settings->depositInPrice($channel),
            $this->texts,
            $this->lang($context),
            fn (float $amount): string => $this->money($amount * $factor, $context)
        );
    }

    /**
     * Shops whose prices hold the deposit: "Included deposit" and "EUR 1.50" for a row of the cart
     * summary, or null when nothing in the cart has a deposit or the deposit is its own cart line.
     *
     * @return array{label: string, amount: string}|null
     */
    public function includedDeposit(mixed $cart, mixed $context): ?array
    {
        if (!$cart instanceof Cart || !$context instanceof SalesChannelContext || !$this->settings->depositInPrice($context->getSalesChannelId())) {
            return null;
        }
        $sum = 0.0;
        foreach ($cart->getLineItems()->filterFlatByType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $item) {
            $each = $item->getPayloadValue(NotesCartProcessor::PAYLOAD_DEPOSIT);
            if (\is_float($each) || \is_int($each)) {
                $sum += $each * $item->getQuantity();
            }
        }

        if ($sum <= 0) {
            return null;
        }
        // "Included deposit: {amount}" in every language: the words before the amount are the label.
        $label = $this->texts->text('deposit.cart_in', $this->lang($context), ['amount' => '']);

        return ['label' => rtrim($label, " :\u{00A0}"), 'amount' => $this->money($sum, $context)];
    }

    /**
     * The short description of a cart or order line item, or ''.
     */
    public function cartDescription(mixed $lineItem): string
    {
        $value = null;
        if ($lineItem instanceof LineItem && $lineItem->hasPayloadValue(NotesCartProcessor::PAYLOAD_DESCRIPTION)) {
            $value = $lineItem->getPayloadValue(NotesCartProcessor::PAYLOAD_DESCRIPTION);
        } elseif ($lineItem instanceof OrderLineItemEntity) {
            $value = $lineItem->getPayload()[NotesCartProcessor::PAYLOAD_DESCRIPTION] ?? null;
        }

        return \is_string($value) ? FoodInformation::plain($value, 200) : '';
    }

    /**
     * @return list<array{key: string, field: string, before: string, link: string, after: string, page: string, text: string}>
     */
    public function checkoutBoxes(mixed $cart, mixed $context): array
    {
        if (!$cart instanceof Cart || !$context instanceof SalesChannelContext) {
            return [];
        }

        return $this->boxes->boxes($cart, $context);
    }

    /**
     * "Please tick this box to continue to checkout." for a box the order was refused for.
     */
    public function checkError(mixed $context): string
    {
        return $context instanceof SalesChannelContext ? $this->texts->text('notices.checkError', $this->lang($context)) : '';
    }

    private function lang(SalesChannelContext $context): string
    {
        return NotesTexts::language((string) $context->getLanguageInfo()->localeCode, $this->settings->shopCountry($context->getSalesChannelId()));
    }

    private function money(float $amount, SalesChannelContext $context): string
    {
        return $this->currency->formatCurrencyByLanguage($amount, $context->getCurrency()->getIsoCode(), $context->getLanguageId(), $context->getContext());
    }
}
