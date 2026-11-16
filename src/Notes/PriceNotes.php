<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * Price notes (PAngV § 3 and § 6, as German shops show them). Shopware already prints "Prices
 * incl. VAT plus shipping costs" with a link to the shipping page, the delivery time and the unit
 * price, so Shopproof keeps those and only changes the tax line where Shopware has no words for
 * the case: the small business scheme, free shipping, and products sold under the margin scheme.
 * Known defects of used goods show below that line. Same wording as the Shopify app's Price and
 * delivery notes block and the WooCommerce plugin. Pure, unit tested alone.
 *
 * With the default settings nothing changes on a product page, so an update never changes a live
 * shop by itself.
 */
final class PriceNotes
{
    public const PREFIX = 'shopproof_price_';

    public const FIELDS = ['margin_scheme', 'defects'];

    public const TAX = ['shopware', 'small'];

    public const SHIPPING = ['shopware', 'free'];

    /**
     * Words for a sold-by-measure quantity in a product name, as the Shopify app's unit price
     * check: "500 g", "0,75 l", "2m".
     */
    private const MEASURE = '/(^|[^\p{L}\d])\d+(?:[.,]\d+)?\s?(mg|g|kg|ml|cl|dl|l|mm|cm|m|m2|m²|m3|m³)(?![\p{L}\d])/iu';

    /**
     * The tax line to show instead of Shopware's own, or null to keep Shopware's. Plain text; the
     * template escapes it and keeps Shopware's link to the shipping page around it.
     *
     * @param array<mixed>|null $customFields
     */
    public static function taxLine(string $tax, string $shipping, string $smallText, ?array $customFields, bool $gross, NotesTexts $texts, string $lang): ?string
    {
        $margin = self::marginScheme($customFields);
        $small = $tax === 'small';
        $free = $shipping === 'free';
        if (!$small && !$free && !$margin) {
            return null;
        }
        if (!$gross && !$small) {
            // Net prices for business customers: Shopware's "excl. VAT" line stays right.
            return null;
        }
        $parts = [];
        if ($small) {
            $own = FoodInformation::plain($smallText, 200);
            $parts[] = $own !== '' ? $own : $texts->text('notices.smallBusiness', $lang);
        } elseif ($margin) {
            $parts[] = $texts->text('notices.marginScheme', $lang);
        } else {
            $parts[] = $texts->text('notices.inclVat', $lang);
        }
        $parts[] = $texts->text($free ? 'notices.freeShipping' : 'notices.plusShipping', $lang);

        return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * "Known defects: ..." for used or B-stock goods, or null.
     *
     * @param array<mixed>|null $customFields
     *
     * @return array{label: string, text: string}|null
     */
    public static function defects(?array $customFields, NotesTexts $texts, string $lang): ?array
    {
        $text = FoodInformation::plain($customFields[self::PREFIX . 'defects'] ?? null, 1000, true);

        return $text === '' ? null : ['label' => $texts->text('notices.defects', $lang), 'text' => $text];
    }

    /**
     * @param array<mixed>|null $customFields
     */
    public static function marginScheme(?array $customFields): bool
    {
        $value = $customFields[self::PREFIX . 'margin_scheme'] ?? null;

        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * Whether a product name says it is sold by a measured quantity, so it needs a unit price.
     */
    public static function looksMeasured(string $text): bool
    {
        return preg_match(self::MEASURE, $text) === 1;
    }
}
