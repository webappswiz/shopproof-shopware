<?php declare(strict_types=1);

namespace Shopproof\Compliance\Installer;

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
    public function __construct(private readonly EntityRepository $profileRepository)
    {
    }

    public function install(Context $context): void
    {
        $this->profileRepository->upsert(self::profiles(), $context);
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
     * @return list<array<string, mixed>>
     */
    public static function profiles(): array
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
            ),
        ];
    }

    /**
     * @param array{string, string}                              $label English and German
     * @param list<array{key: string, mappedKey: string}>         $columns
     * @param list<array{entityName: string, mappedKey: string}> $updateBy
     *
     * @return array<string, mixed>
     */
    private static function profile(string $name, string $entity, array $label, array $columns, array $updateBy): array
    {
        $mapping = [];
        foreach ($columns as $position => $column) {
            $mapping[] = [...$column, 'position' => $position];
        }

        return [
            // Same as Shopware's Uuid::fromStringToHex(), kept free of Shopware so it is unit tested alone.
            'id' => md5('shopproof.import-export.' . $name),
            'technicalName' => $name,
            'label' => $label[0],
            'translations' => [
                'en-GB' => ['label' => $label[0]],
                'de-DE' => ['label' => $label[1]],
            ],
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
