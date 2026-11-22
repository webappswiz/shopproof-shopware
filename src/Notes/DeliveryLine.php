<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * The delivery and payment line (Art. 8(3) of Directive 2011/83/EU; § 312j(1) BGB in Germany):
 * shop websites say at the latest at the start of the ordering process whether there are delivery
 * restrictions and which payment methods they take. One short line in the storefront language:
 * the countries the storefront delivers to (a count when there are more than 12), the shop's own
 * words on restrictions, and the active payment methods. Same wording as the Shopify app and the
 * WooCommerce plugin. Pure, unit tested alone.
 */
final class DeliveryLine
{
    public const MAX_LISTED = 12;

    /**
     * @param list<string> $countries country names in the storefront language
     * @param list<string> $payments  payment method names in the storefront language
     */
    public static function text(array $countries, string $note, array $payments, NotesTexts $texts, string $lang): string
    {
        $parts = [];
        $countries = self::clean($countries);
        if (\count($countries) > self::MAX_LISTED) {
            $parts[] = $texts->text('notices.deliverCount', $lang, ['count' => (string) \count($countries)]);
        } elseif ($countries !== []) {
            $parts[] = $texts->text('notices.deliverTo', $lang) . ' ' . implode(', ', $countries) . '.';
        }
        $note = trim((string) preg_replace('/\s+/u', ' ', strip_tags($note)));
        if ($note !== '') {
            $parts[] = mb_substr($note, 0, 200);
        }
        $payments = self::clean($payments);
        if ($payments !== []) {
            $parts[] = $texts->text('notices.payWith', $lang) . ' ' . implode(', ', $payments) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function clean(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $name = trim(strip_tags($name));
            if ($name !== '' && !\in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }
}
