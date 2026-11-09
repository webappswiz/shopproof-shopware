<?php declare(strict_types=1);

namespace Shopproof\Compliance\Installer;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Storefront\DurabilityLabel;
use Shopproof\Compliance\Storefront\SafetyData;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;

/**
 * Product safety custom fields: one set on products (overrides, identifiers, warnings) and one on
 * manufacturers (address, contact, EU responsible person). Created on install and update, kept
 * in step with this class, removed on uninstall unless the merchant keeps the data.
 */
final class CustomFieldInstaller
{
    public const PRODUCT_SET = 'shopproof_safety';
    public const MANUFACTURER_SET = 'shopproof_safety_manufacturer';
    public const DURABILITY_SET = 'shopproof_durability';
    public const SETS = [self::PRODUCT_SET, self::MANUFACTURER_SET, self::DURABILITY_SET];

    private const LABELS = [
        'manufacturer_name' => ['Manufacturer name', 'Name des Herstellers'],
        'manufacturer_address' => ['Manufacturer postal address', 'Postanschrift des Herstellers'],
        'manufacturer_email' => ['Manufacturer email', 'E-Mail des Herstellers'],
        'manufacturer_url' => ['Manufacturer website or contact page', 'Website oder Kontaktseite des Herstellers'],
        'responsible_name' => ['EU responsible person name (if the manufacturer is outside the EU)', 'Name der verantwortlichen Person in der EU (wenn der Hersteller außerhalb der EU sitzt)'],
        'responsible_address' => ['EU responsible person postal address', 'Postanschrift der verantwortlichen Person in der EU'],
        'responsible_email' => ['EU responsible person email', 'E-Mail der verantwortlichen Person in der EU'],
        'responsible_url' => ['EU responsible person website', 'Website der verantwortlichen Person in der EU'],
        'identifiers' => ['Product identifiers (one per line, for example "Model: X1" or "Batch: 2026-04")', 'Produktkennzeichnung (eine pro Zeile, zum Beispiel "Modell: X1" oder "Charge: 2026-04")'],
        'warnings' => ['Warnings and safety information (in the buyer\'s language)', 'Warnhinweise und Sicherheitsinformationen (in der Sprache der Käufer)'],
        'years' => ['Durability guarantee in years (3 to 99; leave empty when the producer offers none of more than two years)', 'Haltbarkeitsgarantie in Jahren (3 bis 99; leer lassen, wenn der Hersteller keine über zwei Jahre gibt)'],
        'brand' => ['Brand on the label (empty: the manufacturer\'s name)', 'Marke auf dem Label (leer: Name des Herstellers)'],
        'model' => ['Model identifier on the label (empty: the manufacturer number)', 'Modellkennung auf dem Label (leer: Herstellernummer)'],
        'display' => ['How to show the label', 'Darstellung des Labels'],
    ];

    private const MULTILINE = ['manufacturer_address', 'responsible_address', 'identifiers', 'warnings'];

    /**
     * @param EntityRepository<\Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection> $setRepository
     */
    public function __construct(
        private readonly EntityRepository $setRepository,
        private readonly Connection $connection,
    ) {
    }

    public function install(Context $context): void
    {
        $this->setRepository->upsert([
            $this->set(self::PRODUCT_SET, 'product', SafetyData::PREFIX, [...SafetyData::PARTY_FIELDS, ...SafetyData::PRODUCT_FIELDS], [
                'Shopproof: product safety (GPSR)',
                'Shopproof: Produktsicherheit (GPSR)',
            ]),
            $this->set(self::MANUFACTURER_SET, 'product_manufacturer', SafetyData::MANUFACTURER_PREFIX, SafetyData::MANUFACTURER_FIELDS, [
                'Shopproof: product safety (GPSR)',
                'Shopproof: Produktsicherheit (GPSR)',
            ]),
            $this->set(self::DURABILITY_SET, 'product', DurabilityLabel::PREFIX, DurabilityLabel::FIELDS, [
                'Shopproof: EU durability label',
                'Shopproof: EU-Haltbarkeitslabel',
            ]),
        ], $context);
    }

    public function uninstall(Context $context): void
    {
        $ids = $this->setRepository->searchIds(
            (new Criteria())->addFilter(new EqualsAnyFilter('name', self::SETS)),
            $context
        )->getIds();
        if ($ids !== []) {
            $this->setRepository->delete(array_map(static fn ($id): array => ['id' => $id], $ids), $context);
        }
    }

    /**
     * Remove the stored values from products and manufacturers. Used only when the merchant does
     * not keep the data on uninstall.
     */
    public function removeValues(): void
    {
        $keys = [
            'product_translation' => [
                ...array_map(static fn (string $field): string => SafetyData::PREFIX . $field, [...SafetyData::PARTY_FIELDS, ...SafetyData::PRODUCT_FIELDS]),
                ...array_map(static fn (string $field): string => DurabilityLabel::PREFIX . $field, DurabilityLabel::FIELDS),
            ],
            'product_manufacturer_translation' => array_map(
                static fn (string $field): string => SafetyData::MANUFACTURER_PREFIX . $field,
                SafetyData::MANUFACTURER_FIELDS
            ),
        ];
        foreach ($keys as $table => $fields) {
            foreach ($fields as $key) {
                $this->connection->executeStatement(
                    \sprintf('UPDATE `%s` SET custom_fields = JSON_REMOVE(custom_fields, :path) WHERE JSON_CONTAINS_PATH(custom_fields, \'one\', :path)', $table),
                    ['path' => '$.' . $key]
                );
            }
        }
    }

    /**
     * @param list<string>          $fields
     * @param array{string, string} $label
     *
     * @return array<string, mixed>
     */
    private function set(string $name, string $entity, string $prefix, array $fields, array $label): array
    {
        $existingId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM custom_field_set WHERE name = :name', ['name' => $name]);
        $setId = \is_string($existingId) ? $existingId : Uuid::fromStringToHex($name);

        $customFields = [];
        foreach ($fields as $position => $field) {
            $fieldName = $prefix . $field;
            $fieldId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM custom_field WHERE name = :name', ['name' => $fieldName]);
            $config = [
                'componentName' => \in_array($field, self::MULTILINE, true) ? 'sw-textarea-field' : 'sw-text-field',
                'customFieldType' => \in_array($field, self::MULTILINE, true) ? 'textarea' : 'text',
                'customFieldPosition' => $position + 1,
                'label' => ['en-GB' => self::LABELS[$field][0], 'de-DE' => self::LABELS[$field][1]],
            ];
            $type = CustomFieldTypes::TEXT;
            if ($field === 'display') {
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'options' => [
                        ['value' => 'full', 'label' => ['en-GB' => 'Full label', 'de-DE' => 'Ganzes Label']],
                        ['value' => 'nested', 'label' => ['en-GB' => 'Short strip that opens the full label', 'de-DE' => 'Schmaler Streifen, der das ganze Label öffnet']],
                    ],
                ];
            }
            $customFields[] = [
                'id' => \is_string($fieldId) ? $fieldId : Uuid::fromStringToHex($fieldName),
                'name' => $fieldName,
                'type' => $type,
                'config' => $config,
            ];
        }

        $relationId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(r.id)) FROM custom_field_set_relation r JOIN custom_field_set s ON s.id = r.set_id WHERE s.name = :name AND r.entity_name = :entity',
            ['name' => $name, 'entity' => $entity]
        );

        return [
            'id' => $setId,
            'name' => $name,
            'config' => ['label' => ['en-GB' => $label[0], 'de-DE' => $label[1]], 'translated' => true],
            'relations' => [[
                'id' => \is_string($relationId) ? $relationId : Uuid::fromStringToHex($name . '.' . $entity),
                'entityName' => $entity,
            ]],
            'customFields' => $customFields,
        ];
    }
}
