<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Storefront\HazardLabel;
use Shopproof\Compliance\Storefront\Registrations;
use Shopproof\Compliance\Storefront\SafetyData;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads what SetupCheck needs from the shop: storefront sales channels with their languages, the
 * withdrawal button settings per channel, the page and its form, Shopware's withdrawal flow, the
 * records table and failed records, product page layouts, the guarantee notice setting and the
 * manufacturers without product safety details.
 */
class SetupFacts
{
    public const BUTTON = 'core.basicInformation.showRevocationButton';

    public const PAGE = 'core.basicInformation.revocationRequestPage';

    public const DEFAULT_PRODUCT_LAYOUT = 'core.cms.default_product_cms_page';

    private const PLUGIN_CONFIG = 'ShopproofCompliance.config.';

    /** Most manufacturers without details the check names. */
    private const MANUFACTURER_LIMIT = 50;

    private const HAZARD_PAGE = 500;

    /** Shopware's class for the event, present from 6.7.9. */
    private const EVENT_CLASS = 'Shopware\\Core\\Content\\RevocationRequest\\Event\\RevocationRequestEvent';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
        private readonly HazardLabel $hazards,
        private readonly Wording $wording,
        private readonly ReturnsWebhook $returns,
    ) {
    }

    /**
     * @return array{supported: bool, flowActive: bool, customerMail: bool, channels: list<array{id: string, name: string, active: bool, buttonOn: bool, pageId: string, pageExists: bool, pageHasForm: bool, locales: list<string>}>, layouts: list<array{id: string, name: string, elements: list<string>}>, recordTable: bool, failedRecords: int, showSafety: bool, guaranteeNotice: bool, manufacturersWithoutDetails: list<array{id: string, name: string}>, manufacturersWithoutDetailsCount: int, safetyPlacement: string, hazardProblems: list<array{id: string, name: string, codes: list<array{code: string, reason: string}>}>, registrationBadLines: list<string>}
     */
    public function collect(): array
    {
        $missing = $this->manufacturersWithoutDetails();
        $common = [
            'layouts' => $this->layouts(),
            'recordTable' => $this->connection->fetchOne('SHOW TABLES LIKE :table', ['table' => WithdrawalStore::TABLE]) === WithdrawalStore::TABLE,
            'failedRecords' => self::int($this->config->get(WithdrawalSubscriber::FAILURES)),
            'showSafety' => $this->config->getBool(self::PLUGIN_CONFIG . 'showSafety'),
            'guaranteeNotice' => $this->guaranteeNoticeOn(),
            'manufacturersWithoutDetails' => \array_slice($missing, 0, self::MANUFACTURER_LIMIT),
            'manufacturersWithoutDetailsCount' => \count($missing),
            'safetyPlacement' => $this->config->getString(self::PLUGIN_CONFIG . 'safetyPlacement') === 'description' ? 'description' : 'buyBox',
            'hazardProblems' => $this->hazardProblems(),
            'registrationBadLines' => Registrations::badLines($this->config->getString(self::PLUGIN_CONFIG . 'registrationNumbers')),
        ];
        if (!class_exists(self::EVENT_CLASS)) {
            return ['supported' => false, 'flowActive' => false, 'customerMail' => false, 'channels' => [], ...$common];
        }

        $flows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id FROM flow WHERE event_name = :event AND active = 1 AND invalid = 0',
            ['event' => WithdrawalSubscriber::EVENT]
        );
        $customerMail = false;
        foreach ($flows as $flow) {
            $count = self::int($this->connection->fetchOne(
                'SELECT COUNT(*) FROM flow_sequence WHERE flow_id = :flow AND action_name = :action AND config LIKE :recipient',
                ['flow' => Uuid::fromHexToBytes(self::str($flow['id'] ?? null)), 'action' => 'action.mail.send', 'recipient' => '%revocationRequestCustomerFormMail%']
            ));
            $customerMail = $customerMail || $count > 0;
        }

        $channels = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sc.id)) AS id, sc.active, COALESCE(sct.name, \'\') AS name
             FROM sales_channel sc
             LEFT JOIN sales_channel_translation sct ON sct.sales_channel_id = sc.id AND sct.language_id = sc.language_id
             WHERE sc.type_id = :type ORDER BY sct.name',
            ['type' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]
        );
        foreach ($rows as $row) {
            $id = self::str($row['id'] ?? null);
            $pageId = $this->config->get(self::PAGE, $id);
            $pageId = \is_string($pageId) && Uuid::isValid($pageId) ? $pageId : '';
            $channels[] = [
                'id' => $id,
                'name' => self::str($row['name'] ?? null),
                'active' => self::int($row['active'] ?? null) === 1,
                'buttonOn' => (bool) $this->config->get(self::BUTTON, $id),
                'pageId' => $pageId,
                'pageExists' => $pageId !== '' && $this->pageExists($pageId),
                'pageHasForm' => $pageId !== '' && $this->pageHasForm($pageId),
                'locales' => $this->locales($id),
                'wording' => $this->wordingFacts($id),
                'returnsRejected' => $this->returns->urlRejected($id),
            ];
        }

        return ['supported' => true, 'flowActive' => $flows !== [], 'customerMail' => $customerMail, 'channels' => $channels, ...$common];
    }

    /**
     * True when the guarantee notice is on for every active storefront (each can override the
     * setting), or switched on for the whole shop when there is no storefront.
     */
    private function guaranteeNoticeOn(): bool
    {
        $key = self::PLUGIN_CONFIG . 'showGuaranteeNotice';
        $ids = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = :type AND active = 1',
            ['type' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]
        );
        if ($ids === []) {
            return $this->config->getBool($key);
        }
        foreach ($ids as $id) {
            if (!$this->config->getBool($key, self::str($id))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Shopware manufacturers of at least one product whose products get no contact details for
     * them (see SafetyData::manufacturerHasDetails), by name, all of them.
     *
     * @return list<array{id: string, name: string}>
     */
    private function manufacturersWithoutDetails(): array
    {
        $defaults = [];
        foreach (['manufacturer_name' => 'safetyManufacturerName', 'manufacturer_address' => 'safetyManufacturerAddress', 'manufacturer_email' => 'safetyManufacturerEmail', 'manufacturer_url' => 'safetyManufacturerUrl'] as $field => $setting) {
            $defaults[$field] = $this->config->getString(self::PLUGIN_CONFIG . $setting);
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(m.id)) AS id, t.name, t.custom_fields, t.language_id = :language AS system_language
             FROM product_manufacturer m
             INNER JOIN product_manufacturer_translation t ON t.product_manufacturer_id = m.id AND t.product_manufacturer_version_id = m.version_id
             WHERE m.version_id = :version
               AND EXISTS (SELECT 1 FROM product p WHERE p.product_manufacturer_id = m.id AND p.version_id = :version)',
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]
        );
        $names = [];
        $withDetails = [];
        foreach ($rows as $row) {
            $id = self::str($row['id'] ?? null);
            $name = trim(self::str($row['name'] ?? null));
            if (!isset($names[$id]) || ($name !== '' && self::int($row['system_language'] ?? null) === 1)) {
                $names[$id] = $name !== '' ? $name : ($names[$id] ?? '');
            }
            try {
                $fields = json_decode(self::str($row['custom_fields'] ?? null), true, 16, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $fields = null;
            }
            if (SafetyData::manufacturerHasDetails($name, \is_array($fields) ? $fields : null, $defaults)) {
                $withDetails[$id] = true;
            }
        }
        $missing = [];
        foreach ($names as $id => $name) {
            if (!isset($withDetails[$id])) {
                $missing[] = ['id' => $id, 'name' => $name];
            }
        }
        usort($missing, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $missing;
    }

    /**
     * Products with hazard or precautionary statements the storefront cannot show, at most
     * MANUFACTURER_LIMIT, with the codes and why.
     *
     * @return list<array{id: string, name: string, codes: list<array{code: string, reason: string}>}>
     */
    private function hazardProblems(): array
    {
        // In pages of 500 through every product with statements, so a problem is never missed in a
        // large catalogue; stops once MANUFACTURER_LIMIT products are found.
        $found = [];
        $offset = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT LOWER(HEX(p.id)) AS id, t.name, t.custom_fields, LOWER(HEX(p.parent_id)) AS parent_id
                     FROM product p
                     INNER JOIN product_translation t ON t.product_id = p.id AND t.product_version_id = p.version_id
                     WHERE p.version_id = :version
                       AND (JSON_EXTRACT(t.custom_fields, \'$.shopproof_safety_hazard_statements\') IS NOT NULL
                         OR JSON_EXTRACT(t.custom_fields, \'$.shopproof_safety_precautionary_statements\') IS NOT NULL)
                     ORDER BY p.id, t.language_id LIMIT %d OFFSET %d',
                    self::HAZARD_PAGE,
                    $offset
                ),
                ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
            );
            $offset += self::HAZARD_PAGE;
            foreach ($rows as $row) {
                try {
                    $fields = json_decode(self::str($row['custom_fields'] ?? null), true, 16, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                $codes = $this->hazards->problems(\is_array($fields) ? $fields : null);
                if ($codes === []) {
                    continue;
                }
                $id = self::str($row['parent_id'] ?? null) !== '' ? self::str($row['parent_id']) : self::str($row['id'] ?? null);
                $name = trim(self::str($row['name'] ?? null));
                $found[$id] = ['id' => $id, 'name' => $name !== '' ? $name : ($found[$id]['name'] ?? ''), 'codes' => $codes];
            }
        } while (\count($rows) === self::HAZARD_PAGE && \count($found) < self::MANUFACTURER_LIMIT);

        return \array_slice(array_values($found), 0, self::MANUFACTURER_LIMIT);
    }

    /**
     * The product page layouts in use (the shop's default and any set on a product), with the
     * element types each one contains.
     *
     * @return list<array{id: string, name: string, elements: list<string>}>
     */
    private function layouts(): array
    {
        $ids = array_map(static fn (mixed $id): string => self::str($id), $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(cms_page_id)) FROM product WHERE cms_page_id IS NOT NULL AND version_id = :version',
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        ));
        $default = $this->config->get(self::DEFAULT_PRODUCT_LAYOUT);
        if (\is_string($default) && Uuid::isValid($default)) {
            array_unshift($ids, strtolower($default));
        }
        $layouts = [];
        foreach (array_values(array_unique(array_filter($ids, static fn (string $id): bool => Uuid::isValid($id)))) as $id) {
            $name = $this->connection->fetchOne(
                'SELECT t.name FROM cms_page p
                 INNER JOIN cms_page_translation t ON t.cms_page_id = p.id AND t.cms_page_version_id = p.version_id
                 WHERE p.id = :id AND p.version_id = :version AND p.type = :type
                 ORDER BY t.language_id = :language DESC LIMIT 1',
                ['id' => Uuid::fromHexToBytes($id), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'type' => 'product_detail', 'language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]
            );
            if ($name === false) {
                continue;
            }
            $elements = $this->connection->fetchFirstColumn(
                'SELECT DISTINCT s.type FROM cms_slot s
                 INNER JOIN cms_block b ON b.id = s.cms_block_id AND b.version_id = s.cms_block_version_id
                 INNER JOIN cms_section sec ON sec.id = b.cms_section_id AND sec.version_id = b.cms_section_version_id
                 WHERE sec.cms_page_id = :id AND sec.cms_page_version_id = :version',
                ['id' => Uuid::fromHexToBytes($id), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
            );
            $layouts[] = [
                'id' => $id,
                'name' => self::str($name),
                'elements' => array_map(static fn (mixed $type): string => self::str($type), $elements),
            ];
        }

        return $layouts;
    }

    /**
     * @return array{mode: string, ownReady: bool, alternativeLanguages: list<string>}
     */
    private function wordingFacts(string $channelId): array
    {
        $mode = $this->config->getString(self::PLUGIN_CONFIG . 'buttonWording', $channelId);

        return [
            'mode' => \in_array($mode, [Wording::OWN, Wording::ALTERNATIVE], true) ? $mode : Wording::AUTO,
            'ownReady' => $mode === Wording::OWN && $this->wording->labels($channelId, '') !== null,
            'alternativeLanguages' => $this->wording->alternativeLanguages(),
        ];
    }

    private function pageExists(string $pageId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM cms_page WHERE id = :id AND version_id = :version',
            ['id' => Uuid::fromHexToBytes($pageId), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
    }

    private function pageHasForm(string $pageId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM cms_slot s
             INNER JOIN cms_block b ON b.id = s.cms_block_id AND b.version_id = s.cms_block_version_id
             INNER JOIN cms_section sec ON sec.id = b.cms_section_id AND sec.version_id = b.cms_section_version_id
             INNER JOIN cms_slot_translation st ON st.cms_slot_id = s.id AND st.cms_slot_version_id = s.version_id
             WHERE sec.cms_page_id = :id AND sec.cms_page_version_id = :version AND s.type = :type AND st.config LIKE :form
             LIMIT 1',
            ['id' => Uuid::fromHexToBytes($pageId), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'type' => 'form', 'form' => '%"revocationRequest"%']
        );
    }

    /**
     * @return list<string>
     */
    private function locales(string $channelId): array
    {
        $codes = $this->connection->fetchFirstColumn(
            'SELECT lo.code FROM sales_channel_language scl
             INNER JOIN language l ON l.id = scl.language_id
             INNER JOIN locale lo ON lo.id = l.locale_id
             WHERE scl.sales_channel_id = :id ORDER BY lo.code',
            ['id' => Uuid::fromHexToBytes($channelId)]
        );

        return array_map(static fn (mixed $code): string => self::str($code), $codes);
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : (\is_string($value) && is_numeric($value) ? (int) $value : 0);
    }
}
