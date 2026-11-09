<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

/**
 * Checks that Shopware's own withdrawal button (6.7.9 and later) is set up so shoppers can use it:
 * the button is on, it points to a page with the withdrawal form, the flow that confirms receipt to
 * the shopper is active, Shopproof's table for the records exists, and the storefront languages
 * have the national button wording. Also reports requests that could not be recorded, product page
 * layouts and product page warnings (guarantee notice, manufacturers without details). Pure: the
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
     *     layouts?: list<array{id: string, name: string, elements: list<string>}>,
     *     recordTable?: bool,
     *     failedRecords?: int,
     *     showSafety?: bool,
     *     guaranteeNotice?: bool,
     *     manufacturersWithoutDetails?: list<array{id: string, name: string}>
     * } $facts
     *
     * @return array{supported: bool, ok: bool, shop: list<array{key: string, ok: bool}>, channels: list<array{id: string, name: string, ok: bool, checks: list<array{key: string, ok: bool, detail?: string}>}>, layouts: list<array{id: string, name: string, ok: bool, missing: list<string>}>, failedRecords: int, products: list<array{key: string, ok: bool}>, manufacturersWithoutDetails: list<array{id: string, name: string}>}
     */
    public static function evaluate(array $facts): array
    {
        $common = [
            'layouts' => self::layouts($facts['layouts'] ?? []),
            'failedRecords' => max(0, $facts['failedRecords'] ?? 0),
            ...self::products($facts),
        ];
        if (!$facts['supported']) {
            return ['supported' => false, 'ok' => false, 'shop' => [], 'channels' => [], ...$common];
        }
        $recordTable = $facts['recordTable'] ?? true;
        $shop = [
            ['key' => 'flowActive', 'ok' => $facts['flowActive']],
            ['key' => 'customerMail', 'ok' => $facts['flowActive'] && $facts['customerMail']],
            ['key' => 'recordTable', 'ok' => $recordTable],
        ];
        $ok = $facts['flowActive'] && $facts['customerMail'] && $recordTable;
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

        return ['supported' => true, 'ok' => $ok && $channels !== [], 'shop' => $shop, 'channels' => $channels, ...$common];
    }

    /**
     * Product page warnings, which do not change "ok": the EU legal guarantee notice (required
     * for goods from 27 September 2026) is off, or Shopware manufacturers have no Shopproof
     * contact details, so their products show the manufacturer's name only.
     *
     * @param array{showSafety?: bool, guaranteeNotice?: bool, manufacturersWithoutDetails?: list<array{id: string, name: string}>} $facts
     *
     * @return array{products: list<array{key: string, ok: bool}>, manufacturersWithoutDetails: list<array{id: string, name: string}>}
     */
    private static function products(array $facts): array
    {
        $products = [['key' => 'guaranteeNotice', 'ok' => $facts['guaranteeNotice'] ?? false]];
        $missing = [];
        if ($facts['showSafety'] ?? false) {
            $missing = $facts['manufacturersWithoutDetails'] ?? [];
            $products[] = ['key' => 'manufacturerDetails', 'ok' => $missing === []];
        }

        return ['products' => $products, 'manufacturersWithoutDetails' => $missing];
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
