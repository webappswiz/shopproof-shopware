<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

/**
 * Checks that Shopware's own withdrawal button (6.7.9 and later) is set up so shoppers can use it:
 * the button is on, it points to a page with the withdrawal form, the flow that confirms receipt to
 * the shopper is active, and the storefront languages have the national button wording. Pure: the
 * facts come from SetupFacts, so the judgement is unit tested alone.
 */
final class SetupCheck
{
    /** Page designer elements that show the Shopproof product blocks. */
    public const DESCRIPTION = 'product-description-reviews';

    public const BUY_BOX = 'buy-box';

    public const ELEMENT = 'shopproof-product-info';

    /** Storefront languages the plugin's snippets give the national wording for. */
    public const LABEL_LANGUAGES = ['en', 'de', 'fr', 'it', 'nl'];

    /**
     * @param array{
     *     supported: bool,
     *     flowActive: bool,
     *     customerMail: bool,
     *     channels: list<array{id: string, name: string, active: bool, buttonOn: bool, pageId: string, pageExists: bool, pageHasForm: bool, locales: list<string>}>,
     *     layouts?: list<array{id: string, name: string, elements: list<string>}>
     * } $facts
     *
     * @return array{supported: bool, ok: bool, shop: list<array{key: string, ok: bool}>, channels: list<array{id: string, name: string, ok: bool, checks: list<array{key: string, ok: bool, detail?: string}>}>, layouts: list<array{id: string, name: string, ok: bool, missing: list<string>}>}
     */
    public static function evaluate(array $facts): array
    {
        $layouts = self::layouts($facts['layouts'] ?? []);
        if (!$facts['supported']) {
            return ['supported' => false, 'ok' => false, 'shop' => [], 'channels' => [], 'layouts' => $layouts];
        }
        $shop = [
            ['key' => 'flowActive', 'ok' => $facts['flowActive']],
            ['key' => 'customerMail', 'ok' => $facts['flowActive'] && $facts['customerMail']],
        ];
        $ok = $facts['flowActive'] && $facts['customerMail'];
        $channels = [];
        foreach ($facts['channels'] as $channel) {
            if (!$channel['active']) {
                continue;
            }
            $missing = array_values(array_unique(array_filter(
                array_map(static fn (string $locale): string => strtolower(substr($locale, 0, 2)), $channel['locales']),
                static fn (string $lang): bool => $lang !== '' && !\in_array($lang, self::LABEL_LANGUAGES, true)
            )));
            $checks = [
                ['key' => 'buttonOn', 'ok' => $channel['buttonOn']],
                ['key' => 'page', 'ok' => $channel['pageId'] !== '' && $channel['pageExists']],
                ['key' => 'form', 'ok' => $channel['pageExists'] && $channel['pageHasForm']],
                ['key' => 'labels', 'ok' => $missing === [], 'detail' => implode(', ', $missing)],
            ];
            $channelOk = array_reduce($checks, static fn (bool $carry, array $check): bool => $carry && $check['ok'], true);
            $ok = $ok && $channelOk;
            $channels[] = ['id' => $channel['id'], 'name' => $channel['name'], 'ok' => $channelOk, 'checks' => $checks];
        }

        return ['supported' => true, 'ok' => $ok && $channels !== [], 'shop' => $shop, 'channels' => $channels, 'layouts' => $layouts];
    }

    /**
     * Whether each product page layout has a place for the Shopproof blocks: the buy box (durability
     * label) and the description (product safety, guarantee notice), or the Shopproof element.
     *
     * @param list<array{id: string, name: string, elements: list<string>}> $layouts
     *
     * @return list<array{id: string, name: string, ok: bool, missing: list<string>}>
     */
    private static function layouts(array $layouts): array
    {
        $result = [];
        foreach ($layouts as $layout) {
            $has = static fn (string $type): bool => \in_array($type, $layout['elements'], true);
            $missing = [];
            if (!$has(self::ELEMENT)) {
                if (!$has(self::BUY_BOX)) {
                    $missing[] = 'durability';
                }
                if (!$has(self::DESCRIPTION)) {
                    $missing[] = 'safety';
                }
            }
            $result[] = ['id' => $layout['id'], 'name' => $layout['name'], 'ok' => $missing === [], 'missing' => $missing];
        }

        return $result;
    }
}
