<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads what SetupCheck needs from the shop: storefront sales channels with their languages, the
 * withdrawal button settings per channel, the page and its form, and Shopware's withdrawal flow.
 */
class SetupFacts
{
    public const BUTTON = 'core.basicInformation.showRevocationButton';

    public const PAGE = 'core.basicInformation.revocationRequestPage';

    public const DEFAULT_PRODUCT_LAYOUT = 'core.cms.default_product_cms_page';

    /** Shopware's class for the event, present from 6.7.9. */
    private const EVENT_CLASS = 'Shopware\\Core\\Content\\RevocationRequest\\Event\\RevocationRequestEvent';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
    ) {
    }

    /**
     * @return array{supported: bool, flowActive: bool, customerMail: bool, channels: list<array{id: string, name: string, active: bool, buttonOn: bool, pageId: string, pageExists: bool, pageHasForm: bool, locales: list<string>}>, layouts: list<array{id: string, name: string, elements: list<string>}>}
     */
    public function collect(): array
    {
        if (!class_exists(self::EVENT_CLASS)) {
            return ['supported' => false, 'flowActive' => false, 'customerMail' => false, 'channels' => [], 'layouts' => $this->layouts()];
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
            ];
        }

        return ['supported' => true, 'flowActive' => $flows !== [], 'customerMail' => $customerMail, 'channels' => $channels, 'layouts' => $this->layouts()];
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
