<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Withdrawal\OrderEmailLine;
use Shopproof\Compliance\Withdrawal\PolicyMatch;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads what NotesCheck needs from the shop. Uses the shop-wide settings (a sales channel can
 * override them, as for every Shopproof setting).
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
    ) {
    }

    /**
     * @return array{unitPriceGaps: list<array{id: string, name: string}>, depositBadLines: list<string>, missingPolicyPages: list<string>, dispute: array{choice: string, statements: list<string>, foundOnPages: bool, pasted: bool, pagesChecked: bool}, policyMatch?: array{page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null}}
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
     * Basic information, compared with Shopproof's withdrawal period and the shop email.
     *
     * @return array{page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null}
     */
    private function policyMatch(string $pageId): array
    {
        if ($this->policy === null || $pageId === '' || !$this->pageExists($pageId)) {
            return ['page' => false, 'result' => null];
        }

        return [
            'page' => true,
            'result' => $this->policy->match(PolicyMatch::plain($this->pageText([$pageId])), [
                'days' => OrderEmailLine::period($this->config->getInt('ShopproofCompliance.config.withdrawalDays')),
                'emails' => [$this->config->getString('core.basicInformation.email')],
            ]),
        ];
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
