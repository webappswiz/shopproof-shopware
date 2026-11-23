<?php declare(strict_types=1);

namespace Shopproof\Compliance\Installer;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Storefront\HazardLabel;
use Shopproof\Compliance\Storefront\SafetyData;
use Shopware\Core\Content\ImportExport\ImportExportProfileEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;

/**
 * Two profiles for Shopware's own Import/Export (Settings > Import/Export), so a shop can fill in
 * the product safety details of many products or manufacturers at once in a spreadsheet: export,
 * fill in the columns, import the same file. Products are matched by product number, manufacturers
 * by their id, and an import never creates a product or manufacturer. Shopware's bulk edit in the
 * product list covers the same fields as custom fields.
 *
 * Created on install and update (fixed ids, so an update replaces the columns); removed on
 * uninstall unless the merchant keeps the data.
 */
final class ImportExportProfileInstaller
{
    public const PRODUCT_PROFILE = 'shopproof_product_safety';
    public const MANUFACTURER_PROFILE = 'shopproof_manufacturer_safety';
    public const PROFILES = [self::PRODUCT_PROFILE, self::MANUFACTURER_PROFILE];

    /**
     * @param EntityRepository<EntityCollection<ImportExportProfileEntity>> $profileRepository
     */
    public function __construct(
        private readonly EntityRepository $profileRepository,
        private readonly ?Connection $connection = null,
    ) {
    }

    public function install(Context $context): void
    {
        $this->profileRepository->upsert(self::profiles($this->locales()), $context);
    }

    /**
     * Locale codes of the shop's languages. A profile label can only be written for a language
     * the shop has.
     *
     * @return list<string>
     */
    private function locales(): array
    {
        if ($this->connection === null) {
            return [];
        }
        $codes = $this->connection->fetchFirstColumn('SELECT lo.code FROM language l JOIN locale lo ON lo.id = l.locale_id');

        return array_values(array_filter($codes, 'is_string'));
    }

    public function uninstall(Context $context): void
    {
        $ids = $this->profileRepository->searchIds(
            (new Criteria())->addFilter(new EqualsAnyFilter('technicalName', self::PROFILES)),
            $context
        )->getIds();
        if ($ids !== []) {
            $this->profileRepository->delete(array_map(static fn ($id): array => ['id' => $id], $ids), $context);
        }
    }

    /**
     * Both profiles as Shopware entity payloads. Pure, so the columns are unit tested.
     *
     * @param list<string> $locales the shop's locale codes, for labels beyond English and German
     *
     * @return list<array<string, mixed>>
     */
    public static function profiles(array $locales = []): array
    {
        $productFields = [...SafetyData::PARTY_FIELDS, ...SafetyData::PRODUCT_FIELDS, ...HazardLabel::FIELDS];

        return [
            self::profile(
                self::PRODUCT_PROFILE,
                'product',
                ['Shopproof: product safety', 'Shopproof: Produktsicherheit'],
                [
                    ['key' => 'productNumber', 'mappedKey' => 'product_number'],
                    ['key' => 'translations.DEFAULT.name', 'mappedKey' => 'name'],
                    ...array_map(static fn (string $field): array => [
                        'key' => 'translations.DEFAULT.customFields.' . SafetyData::PREFIX . $field,
                        'mappedKey' => $field,
                    ], $productFields),
                ],
                [['entityName' => 'product', 'mappedKey' => 'productNumber']],
                $locales,
            ),
            self::profile(
                self::MANUFACTURER_PROFILE,
                'product_manufacturer',
                ['Shopproof: manufacturer safety details', 'Shopproof: Herstellerangaben zur Produktsicherheit'],
                [
                    ['key' => 'id', 'mappedKey' => 'id'],
                    ['key' => 'translations.DEFAULT.name', 'mappedKey' => 'name'],
                    ...array_map(static fn (string $field): array => [
                        'key' => 'translations.DEFAULT.customFields.' . SafetyData::MANUFACTURER_PREFIX . $field,
                        'mappedKey' => $field,
                    ], SafetyData::MANUFACTURER_FIELDS),
                ],
                [],
                $locales,
            ),
        ];
    }

    /**
     * @param array{string, string}                              $label English and German
     * @param list<array{key: string, mappedKey: string}>         $columns
     * @param list<array{entityName: string, mappedKey: string}> $updateBy
     * @param list<string>                                         $locales
     *
     * @return array<string, mixed>
     */
    private static function profile(string $name, string $entity, array $label, array $columns, array $updateBy, array $locales): array
    {
        $translations = [];
        foreach (LabelTexts::of($label[0], $label[1]) as $locale => $text) {
            if ($locale === 'en-GB' || $locale === 'de-DE' || \in_array($locale, $locales, true)) {
                $translations[$locale] = ['label' => $text];
            }
        }

        $mapping = [];
        foreach ($columns as $position => $column) {
            $mapping[] = [...$column, 'position' => $position];
        }

        return [
            // Same as Shopware's Uuid::fromStringToHex(), kept free of Shopware so it is unit tested alone.
            'id' => md5('shopproof.import-export.' . $name),
            'technicalName' => $name,
            'label' => $label[0],
            'translations' => $translations,
            'systemDefault' => false,
            'sourceEntity' => $entity,
            'fileType' => 'text/csv',
            'delimiter' => ';',
            'enclosure' => '"',
            'type' => 'import-export',
            // Update only: a row whose product or manufacturer does not exist is skipped.
            'config' => ['createEntities' => false, 'updateEntities' => true],
            'mapping' => $mapping,
            'updateBy' => $updateBy,
        ];
    }
}
