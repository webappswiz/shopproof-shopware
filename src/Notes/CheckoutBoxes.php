<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * Checkout tick boxes: the withdrawal policy, the privacy notice and up to three of the shop's
 * own, as Germanized offers. They sit on Shopware's order confirmation page right below its own
 * terms checkbox, where shoppers expect them, and the order cannot be placed until each is ticked.
 * Which boxes were ticked, and when, is saved on the order. The terms box is Shopware's own
 * (Settings > Checkout), so Shopproof never shows a second one. A box shows only once the shop
 * chooses it. Same wording as the Shopify app and the WooCommerce plugin. Pure, unit tested alone.
 */
final class CheckoutBoxes
{
    public const POLICY = ['withdrawal', 'privacy'];

    public const CUSTOM = ['custom1', 'custom2', 'custom3'];

    /** The order custom field with the ticked boxes as list of {key, text, time}. */
    public const ORDER_FIELD = 'shopproof_checks';

    /** The same as readable lines, for the order's custom fields in the admin. */
    public const ORDER_TEXT_FIELD = 'shopproof_checks_text';

    public const ORDER_SET = 'shopproof_order';

    /**
     * The request field of a box, as Shopware's own "tos" field: "shopproofCheckWithdrawal".
     */
    public static function field(string $key): string
    {
        return 'shopproofCheck' . ucfirst($key);
    }

    /**
     * The boxes for this cart, in the order the shop chose. Policy boxes without their page are
     * left out (nobody can read a policy that is not linked), and an own box limited to a product
     * tag shows only when a product in the cart has that tag.
     *
     * @param list<string>          $chosen     keys from the setting, in its order
     * @param array<string, string> $pages      'withdrawal' and 'privacy' => CMS page id or ''
     * @param array<string, string> $custom     'custom1' => the shop's text
     * @param array<string, string> $tags       'custom1' => tag name or ''
     * @param list<string>          $cartTags   lower case tag names of the products in the cart
     *
     * @return list<array{key: string, field: string, before: string, link: string, after: string, page: string, text: string}>
     */
    public static function boxes(array $chosen, array $pages, array $custom, array $tags, array $cartTags, NotesTexts $texts, string $lang): array
    {
        $boxes = [];
        foreach (array_values(array_unique($chosen)) as $key) {
            if (\in_array($key, self::POLICY, true)) {
                $page = $pages[$key] ?? '';
                if ($page === '') {
                    continue;
                }
                $split = self::splitLink($texts->text('notices.' . $key, $lang));
                $boxes[] = ['key' => $key, 'field' => self::field($key), ...$split, 'page' => $page, 'text' => $split['before'] . $split['link'] . $split['after']];
                continue;
            }
            if (!\in_array($key, self::CUSTOM, true)) {
                continue;
            }
            $text = FoodInformation::plain($custom[$key] ?? '', 300);
            if ($text === '') {
                continue;
            }
            $tag = mb_strtolower(trim($tags[$key] ?? ''));
            if ($tag !== '' && !\in_array($tag, $cartTags, true)) {
                continue;
            }
            $boxes[] = ['key' => $key, 'field' => self::field($key), 'before' => $text, 'link' => '', 'after' => '', 'page' => '', 'text' => $text];
        }

        return $boxes;
    }

    /**
     * 'I have read the <a href="{link}">withdrawal policy</a>.' as the words before, in and after
     * the link, so the template escapes each and links the middle part the way Shopware links its
     * terms (a button that opens the page in a window).
     *
     * @return array{before: string, link: string, after: string}
     */
    public static function splitLink(string $html): array
    {
        if (preg_match('#^(.*?)<a\b[^>]*>(.*?)</a>(.*)$#su', $html, $m) === 1) {
            return [
                'before' => html_entity_decode(strip_tags($m[1]), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                'link' => html_entity_decode(strip_tags($m[2]), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                'after' => html_entity_decode(strip_tags($m[3]), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            ];
        }

        return ['before' => html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), 'link' => '', 'after' => ''];
    }

    /**
     * The record saved on the order: the boxes that were ticked, with their words and the time.
     *
     * @param list<array{key: string, text: string}> $boxes
     * @param array<string, mixed>                   $posted request fields
     *
     * @return list<array{key: string, text: string, time: string}>
     */
    public static function record(array $boxes, array $posted, \DateTimeInterface $now): array
    {
        $checks = [];
        foreach ($boxes as $box) {
            $value = $posted[self::field($box['key'])] ?? null;
            if ($value !== null && $value !== '' && $value !== false && $value !== '0') {
                $checks[] = ['key' => $box['key'], 'text' => $box['text'], 'time' => $now->format(\DATE_ATOM)];
            }
        }

        return $checks;
    }

    /**
     * The record as lines for the order's custom fields in the admin.
     *
     * @param list<array{key: string, text: string, time: string}> $checks
     */
    public static function recordText(array $checks): string
    {
        return implode("\n", array_map(static fn (array $check): string => $check['text'] . ' (' . $check['time'] . ')', $checks));
    }
}
