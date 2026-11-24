<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Withdrawal\OrderEmailLine;
use Shopproof\Compliance\Withdrawal\OrderEmailSubscriber;
use Shopproof\Compliance\Withdrawal\PolicyMatch;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads what NotesCheck needs from the shop. Uses the shop-wide settings (a sales channel can
 * override them, as for every Shopproof setting), except the withdrawal policy match, which checks
 * each active storefront with its own revocation page, withdrawal period and shop email.
 */
class NotesFacts
{
    /** Most products without a unit price the check names. */
    private const GAP_LIMIT = 50;

    private const PAGE = 500;

    /** Stops after this many products, so the check stays quick on a large catalogue. */
    private const SCAN_LIMIT = 20000;

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
        private readonly NotesSettings $settings,
        private readonly NotesTexts $texts,
        private readonly ?PolicyMatch $policy = null,
        private readonly ?OrderButtonFacts $orderButton = null,
        private readonly string $orderButtonWords = __DIR__ . '/../Resources/config/order-button.json',
        private readonly ?OrderEmailSubscriber $emails = null,
        private readonly ?PriceHistory $prices = null,
    ) {
    }

    /**
     * @return array{unitPriceGaps: list<array{id: string, name: string}>, depositBadLines: list<string>, missingPolicyPages: list<string>, dispute: array{choice: string, statements: list<string>, foundOnPages: bool, pasted: bool, pagesChecked: bool}, policyMatch?: array{page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null, online?: array{status: string, lang: string|null, reason: string|null}, sentence?: string, channels?: list<array{names: list<string>, page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null, online?: array{status: string, lang: string|null, reason: string|null}, sentence?: string}>}, orderButton?: array{buttons: list<array{channel: string, locale: string, text: string}>, words: array<string, string>}, deliveryLine?: array{on: bool}, priorPrice?: array{on: bool, onSale: int, missing: list<string>, higher: list<string>, brokenSeals: int, capped?: bool}, legalTexts?: array{provider: string, button: bool}}
     */
    public function collect(): array
    {
        $pages = $this->settings->policyPages(null);
        $missing = [];
        foreach ($this->settings->checkoutBoxes(null) as $key) {
            if (\in_array($key, CheckoutBoxes::POLICY, true) && $pages[$key] === '') {
                $missing[] = $key;
            }
        }
        $choice = $this->settings->disputeChoice(null);
        $statements = [];
        foreach (NotesTexts::LANGUAGES as $lang) {
            $text = DisputeStatement::text($choice, $this->settings->string('disputeBoard', null), $this->settings->string('disputeUrl', null), $this->texts, $lang);
            if ($text !== '') {
                $statements[] = $text;
            }
        }
        $legalPages = $this->legalPages();
        $policy = [];
        if ($this->policy !== null) {
            $policy['policyMatch'] = $this->policyMatch($pages['withdrawal']);
            $legal = $this->legalTexts();
            if ($legal !== null) {
                $policy['legalTexts'] = $legal;
            }
        }
        $policy['deliveryLine'] = ['on' => $this->settings->bool('deliveryLine', null)];
        if ($this->orderButton !== null) {
            $policy['orderButton'] = ['buttons' => $this->orderButton->collect(), 'words' => OrderButtonCheck::words($this->orderButtonWords)];
        }
        if ($this->prices !== null) {
            $sale = $this->prices->saleCheck();
            $policy['priorPrice'] = [
                'on' => $this->settings->bool('priorPriceLine', null),
                'onSale' => $sale['onSale'],
                'missing' => array_map(static fn (array $p): string => $p['name'] !== '' ? $p['name'] : $p['id'], $sale['missing']),
                'higher' => array_map(static fn (array $p): string => $p['name'] !== '' ? $p['name'] : $p['id'], $sale['higher']),
                'brokenSeals' => \count($this->prices->verifySeals()['broken']),
                'capped' => $sale['capped'],
            ];
        }

        return [
            'unitPriceGaps' => $this->unitPriceGaps(),
            'depositBadLines' => $this->settings->depositTypes(null)['bad'],
            'missingPolicyPages' => $missing,
            'dispute' => [
                'choice' => $choice,
                'statements' => $statements,
                'foundOnPages' => $statements !== [] && $legalPages !== [] && DisputeStatement::foundIn($this->pageText($legalPages), $statements),
                'pasted' => $this->settings->bool('disputePasted', null),
                'pagesChecked' => $legalPages !== [],
            ],
            ...$policy,
        ];
    }

    /**
     * The withdrawal policy match check (decision 0039): the revocation page set in Settings >
     * Basic information, compared with Shopproof's withdrawal period and the shop email. Each of
     * the three can be set per sales channel, and the withdrawal form, its emails and the deadline
     * clock use the channel's values, so each active storefront is checked with its own. Storefronts
     * with the same page, period and email are checked once. When they all agree (or there is no
     * active storefront, then the "All sales channels" values count) the result has one part as
     * before; otherwise "channels" holds one part per group, named by its storefronts.
     *
     * @return array{page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null, online?: array{status: string, lang: string|null, reason: string|null}, sentence?: string, channels?: list<array{names: list<string>, page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null, online?: array{status: string, lang: string|null, reason: string|null}, sentence?: string}>}
     */
    private function policyMatch(string $shopPageId): array
    {
        $groups = [];
        $channels = $this->storefronts();
        if ($channels === []) {
            $channels = [['id' => null, 'name' => '']];
        }
        foreach ($channels as $channel) {
            $pageId = $channel['id'] === null ? $shopPageId : $this->settings->policyPages($channel['id'])['withdrawal'];
            $days = OrderEmailLine::period($this->config->getInt('ShopproofCompliance.config.withdrawalDays', $channel['id']));
            $email = $this->config->get('core.basicInformation.email', $channel['id']);
            $email = \is_string($email) ? trim($email) : '';
            $key = $pageId . '|' . $days . '|' . strtolower($email);
            $groups[$key] ??= ['names' => [], 'pageId' => $pageId, 'days' => $days, 'email' => $email, 'channel' => $channel['id']];
            if ($channel['name'] !== '') {
                $groups[$key]['names'][] = $channel['name'];
            }
        }

        $parts = [];
        foreach ($groups as $group) {
            $parts[] = ['names' => $group['names']] + $this->policyPart($group['pageId'], $group['days'], $group['email'], $group['channel']);
        }
        $first = $parts[0];
        $out = ['page' => $first['page'], 'result' => $first['result']];
        foreach (['online', 'sentence'] as $key) {
            if (isset($first[$key])) {
                $out[$key] = $first[$key];
            }
        }
        if (\count($parts) > 1) {
            $out['channels'] = $parts;
        }

        return $out;
    }

    /**
     * @return array{page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null, online?: array{status: string, lang: string|null, reason: string|null}, sentence?: string}
     */
    private function policyPart(string $pageId, int $days, string $email, ?string $channel = null): array
    {
        if ($this->policy === null || $pageId === '' || !$this->pageExists($pageId)) {
            return ['page' => false, 'result' => null];
        }
        $text = PolicyMatch::plain($this->pageText([$pageId]));
        $online = $this->policy->online($text);
        $out = [
            'page' => true,
            'result' => $this->policy->match($text, [
                'days' => $days,
                'emails' => [$email],
            ]),
            'online' => $online,
        ];
        if ($online['status'] !== PolicyMatch::FOUND) {
            // The official sentence to copy, in the policy's language (else the storefront's), with
            // the address of the withdrawal button's page when the storefront has one.
            [$languageId, $locale] = $this->channelLanguage($channel);
            $url = $channel !== null && $languageId !== '' && $this->emails !== null ? $this->emails->withdrawalUrl($channel, $languageId) : '';
            $out['sentence'] = $this->policy->onlineSentence($online['lang'] ?? $locale, $url);
        }

        return $out;
    }

    /**
     * Legal text providers' Shopware plugins, by technical name prefix and author (decision 0048),
     * in order: when several are active, the notes name the first. Names come from the Shopware Store
     * (24 Nov 2026); the author match catches a renamed plugin. Händlerbund: its own plugin
     * ("Händlerbund Integration for Shopware 6", technical names starting with Hbdev) and the
     * older nvii-media one (NviimHaendlerbundApiplugin). IT-Recht Kanzlei: "AGB-Schnittstelle"
     * (nwdxxItRechtConnector6). Protected Shops: "AGB Connect" (PSAGBConnect6). janolaw and
     * avalex: their legal text plugins.
     */
    public const PROVIDERS = [
        'Händlerbund' => ['names' => ['Hbdev', 'NviimHaendlerbundApiplugin'], 'author' => 'ndlerbund'],
        'IT-Recht Kanzlei' => ['names' => ['nwdxxItRecht'], 'author' => 'IT-Recht'],
        'Protected Shops' => ['names' => ['PSAGBConnect'], 'author' => 'Protected Shops'],
        'janolaw' => ['names' => ['Janolaw'], 'author' => 'janolaw'],
        'avalex' => ['names' => ['Avalex'], 'author' => 'avalex'],
    ];

    /**
     * The provider of an installed plugin, or null when it is not a known legal text plugin.
     */
    public static function providerOf(string $name, string $author): ?string
    {
        foreach (self::PROVIDERS as $provider => $match) {
            foreach ($match['names'] as $prefix) {
                if (stripos($name, $prefix) === 0) {
                    return $provider;
                }
            }
            if (stripos($author, $match['author']) !== false) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Is this Händlerbund setting the public shop ID that turns on its own withdrawal button? Only
     * a key whose last part is the shop ID itself (shopId, publicShopId, shop_id) counts. Other
     * settings that name the revocation or withdrawal, such as which page holds the text, say
     * nothing about the button, so they stay silent.
     */
    public static function isButtonShopId(string $key): bool
    {
        $last = substr($key, (int) strrpos('.' . $key, '.'));

        return preg_match('/^(public_?)?shop_?id$/i', $last) === 1;
    }

    /**
     * A legal text provider's plugin that fills the shop's legal pages (decision 0048), so the
     * notes can name it and say where to fix a difference. Since version 4.0 Händlerbund's own
     * plugin can also add a withdrawal button to the footer once a public shop ID is entered;
     * "button" is true only when such a value is set in its settings and Shopware's own
     * withdrawal button is on too, so customers would see two. The other providers' plugins add
     * no button. Unknown settings stay silent.
     *
     * @return array{provider: string, button: bool}|null
     */
    private function legalTexts(): ?array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT name, COALESCE(author, '') AS author, version FROM plugin
                 WHERE active = 1 AND installed_at IS NOT NULL"
            );
        } catch (\Throwable) {
            return null;
        }
        $found = [];
        $button = false;
        foreach ($rows as $row) {
            $name = \is_string($row['name'] ?? null) ? $row['name'] : '';
            $author = \is_string($row['author'] ?? null) ? $row['author'] : '';
            $provider = $name !== '' ? self::providerOf($name, $author) : null;
            if ($provider === null) {
                continue;
            }
            $found[$provider] = true;
            $version = \is_string($row['version'] ?? null) ? $row['version'] : '0';
            if ($provider !== 'Händlerbund' || $name === 'NviimHaendlerbundApiplugin' || version_compare($version, '4.0.0', '<')) {
                continue;
            }
            $values = $this->connection->fetchAllAssociative(
                'SELECT configuration_key, configuration_value FROM system_config WHERE configuration_key LIKE :prefix',
                ['prefix' => addcslashes($name, '%_') . '.config.%']
            );
            foreach ($values as $value) {
                $key = \is_string($value['configuration_key'] ?? null) ? $value['configuration_key'] : '';
                $decoded = \is_string($value['configuration_value'] ?? null) ? json_decode($value['configuration_value'], true) : null;
                $set = \is_array($decoded) && \is_string($decoded['_value'] ?? null) && trim($decoded['_value']) !== '';
                if ($set && self::isButtonShopId($key)) {
                    $button = true;
                }
            }
        }
        $names = array_values(array_filter(array_keys(self::PROVIDERS), static fn (string $p): bool => isset($found[$p])));
        if ($names === []) {
            return null;
        }

        return [
            'provider' => $names[0],
            'button' => $button && $this->config->getBool(\Shopproof\Compliance\Withdrawal\SetupFacts::BUTTON),
        ];
    }

    /**
     * The storefront's language id and locale code (for example de-DE); the shop's system
     * language when there is no storefront.
     *
     * @return array{0: string, 1: string}
     */
    private function channelLanguage(?string $channel): array
    {
        $row = $channel !== null
            ? $this->connection->fetchAssociative(
                'SELECT LOWER(HEX(l.id)) AS id, lo.code AS code FROM sales_channel sc INNER JOIN language l ON l.id = sc.language_id LEFT JOIN locale lo ON lo.id = l.locale_id WHERE sc.id = :channel',
                ['channel' => Uuid::fromHexToBytes($channel)]
            )
            : $this->connection->fetchAssociative(
                'SELECT LOWER(HEX(l.id)) AS id, lo.code AS code FROM language l LEFT JOIN locale lo ON lo.id = l.locale_id WHERE l.id = :id',
                ['id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]
            );
        if (!\is_array($row)) {
            return ['', 'en'];
        }

        return [\is_string($row['id'] ?? null) ? $row['id'] : '', \is_string($row['code'] ?? null) ? $row['code'] : 'en'];
    }

    /**
     * The active storefronts, by name.
     *
     * @return list<array{id: string, name: string}>
     */
    private function storefronts(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sc.id)) AS id, COALESCE(sct.name, \'\') AS name
             FROM sales_channel sc
             LEFT JOIN sales_channel_translation sct ON sct.sales_channel_id = sc.id AND sct.language_id = sc.language_id
             WHERE sc.type_id = :type AND sc.active = 1 ORDER BY sct.name',
            ['type' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]
        );
        $channels = [];
        foreach ($rows as $row) {
            $id = \is_string($row['id'] ?? null) ? $row['id'] : '';
            if ($id !== '') {
                $channels[] = ['id' => $id, 'name' => \is_string($row['name'] ?? null) ? $row['name'] : ''];
            }
        }

        return $channels;
    }

    private function pageExists(string $pageId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM cms_page WHERE id = :id AND version_id = :version',
            ['id' => Uuid::fromHexToBytes($pageId), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
    }

    /**
     * Active products without variants whose name says they are sold by weight, volume, length
     * or area ("500 g", "0,75 l") but have no content and unit set, so Shopware shows no unit
     * price. Variants count with their main product's values where they have none.
     *
     * @return list<array{id: string, name: string}>
     */
    private function unitPriceGaps(): array
    {
        $found = [];
        $offset = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT LOWER(HEX(p.id)) AS id, COALESCE(t.name, pt.name) AS name
                     FROM product p
                     LEFT JOIN product pp ON pp.id = p.parent_id AND pp.version_id = p.version_id
                     LEFT JOIN product_translation t ON t.product_id = p.id AND t.product_version_id = p.version_id AND t.language_id = :language
                     LEFT JOIN product_translation pt ON pt.product_id = pp.id AND pt.product_version_id = pp.version_id AND pt.language_id = :language
                     WHERE p.version_id = :version AND p.child_count = 0
                       AND COALESCE(p.active, pp.active) = 1
                       AND (COALESCE(p.purchase_unit, pp.purchase_unit) IS NULL OR COALESCE(p.unit_id, pp.unit_id) IS NULL)
                     ORDER BY p.id LIMIT %d OFFSET %d',
                    self::PAGE,
                    $offset
                ),
                ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]
            );
            $offset += self::PAGE;
            foreach ($rows as $row) {
                $name = \is_string($row['name'] ?? null) ? trim($row['name']) : '';
                $id = \is_string($row['id'] ?? null) ? $row['id'] : '';
                if ($id !== '' && PriceNotes::looksMeasured($name)) {
                    $found[] = ['id' => $id, 'name' => $name];
                }
            }
        } while (\count($rows) === self::PAGE && \count($found) < self::GAP_LIMIT && $offset < self::SCAN_LIMIT);

        return \array_slice($found, 0, self::GAP_LIMIT);
    }

    /**
     * The terms and imprint pages set in Shopware, for the whole shop and each storefront.
     *
     * @return list<string> CMS page ids
     */
    private function legalPages(): array
    {
        $channels = [null, ...array_map(
            static fn (mixed $id): string => \is_string($id) ? $id : '',
            $this->connection->fetchFirstColumn(
                'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = :type AND active = 1',
                ['type' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]
            )
        )];
        $pages = [];
        foreach ($channels as $channel) {
            foreach (['core.basicInformation.tosPage', 'core.basicInformation.imprintPage'] as $setting) {
                $id = $this->config->get($setting, $channel === '' ? null : $channel);
                if (\is_string($id) && Uuid::isValid($id)) {
                    $pages[$id] = $id;
                }
            }
        }

        return array_values($pages);
    }

    /**
     * The texts of all slots of these pages, in every language, as one string.
     *
     * @param list<string> $pageIds
     */
    private function pageText(array $pageIds): string
    {
        $configs = $this->connection->fetchFirstColumn(
            'SELECT st.config FROM cms_slot s
             INNER JOIN cms_block b ON b.id = s.cms_block_id AND b.version_id = s.cms_block_version_id
             INNER JOIN cms_section sec ON sec.id = b.cms_section_id AND sec.version_id = b.cms_section_version_id
             INNER JOIN cms_slot_translation st ON st.cms_slot_id = s.id AND st.cms_slot_version_id = s.version_id
             WHERE sec.cms_page_id IN (:ids) AND sec.cms_page_version_id = :version',
            ['ids' => array_map(static fn (string $id): string => Uuid::fromHexToBytes($id), $pageIds), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY]
        );
        $text = '';
        foreach ($configs as $config) {
            $decoded = \is_string($config) ? json_decode($config, true) : null;
            if (!\is_array($decoded)) {
                continue;
            }
            array_walk_recursive($decoded, static function (mixed $value) use (&$text): void {
                if (\is_string($value)) {
                    $text .= ' ' . $value;
                }
            });
        }

        return $text;
    }
}
