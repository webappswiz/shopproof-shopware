<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * The review check notice (Directive 2005/29/EC, Art. 7(6), added by Directive (EU) 2019/2161):
 * a shop that shows customer reviews says whether and how it checks that they come from customers
 * who bought or used the product. One short line right below the reviews heading. Off until the
 * shop picks its answer. Same choices and wording as the Shopify app and the WooCommerce plugin.
 * Pure, unit tested alone.
 */
final class ReviewNotice
{
    public const CHOICES = ['orders', 'marked', 'none', 'own'];

    private const KEYS = [
        'orders' => 'notices.reviewsOrders',
        'marked' => 'notices.reviewsMarked',
        'none' => 'notices.reviewsNone',
    ];

    /**
     * The notice as plain text in a language, or '' until the shop picks an answer (or picks
     * "own" without its own words).
     */
    public static function text(string $choice, string $how, NotesTexts $texts, string $lang): string
    {
        if (!\in_array($choice, self::CHOICES, true)) {
            return '';
        }
        $how = FoodInformation::plain($how, 300);
        $parts = [];
        if (isset(self::KEYS[$choice])) {
            $parts[] = $texts->text(self::KEYS[$choice], $lang);
        }
        if ($how !== '') {
            $parts[] = $how;
        }

        return trim(implode(' ', array_filter($parts)));
    }
}
