<?php declare(strict_types=1);

namespace Shopproof\Compliance\Installer;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Notes\CheckoutBoxes;
use Shopproof\Compliance\Notes\Deposit;
use Shopproof\Compliance\Notes\FoodInformation;
use Shopproof\Compliance\Notes\NotesCartProcessor;
use Shopproof\Compliance\Notes\PriceNotes;
use Shopproof\Compliance\Storefront\DurabilityLabel;
use Shopproof\Compliance\Storefront\HazardLabel;
use Shopproof\Compliance\Storefront\Registrations;
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
    public const HAZARD_SET = 'shopproof_hazard';
    public const TAKEBACK_SET = 'shopproof_takeback';
    public const FOOD_SET = 'shopproof_food';
    public const NOTES_SET = 'shopproof_notes';
    public const ORDER_SET = CheckoutBoxes::ORDER_SET;
    public const SETS = [self::PRODUCT_SET, self::MANUFACTURER_SET, self::DURABILITY_SET, self::HAZARD_SET, self::TAKEBACK_SET, self::FOOD_SET, self::NOTES_SET, self::ORDER_SET];

    /** The price, deposit and cart description fields, by their full names. */
    public const NOTES_FIELDS = [
        NotesCartProcessor::DESCRIPTION_FIELD,
        PriceNotes::PREFIX . 'margin_scheme',
        PriceNotes::PREFIX . 'defects',
        Deposit::PREFIX . 'types',
        Deposit::PREFIX . 'units',
    ];

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
        'hazard_pictograms' => ['Hazard pictograms (copy them from the label or safety data sheet)', 'Gefahrenpiktogramme (vom Etikett oder Sicherheitsdatenblatt übernehmen)'],
        'hazard_signal' => ['Signal word', 'Signalwort'],
        'hazard_statements' => ['Hazard statements, one code per line (H225, EUH066); own words after a colon', 'Gefahrenhinweise, ein Code pro Zeile (H225, EUH066); eigene Worte nach einem Doppelpunkt'],
        'precautionary_statements' => ['Precautionary statements, one code per line (P210, P305+P351+P338); own words after a colon', 'Sicherheitshinweise, ein Code pro Zeile (P210, P305+P351+P338); eigene Worte nach einem Doppelpunkt'],
        'weee' => ['Electrical equipment: show how to return old devices on this product page', 'Elektrogerät: auf dieser Produktseite zeigen, wie Altgeräte zurückgegeben werden'],
        'digital_consent' => ['Digital content consent at checkout (what the shopper ticked, and when)', 'Zustimmung zu digitalen Inhalten an der Kasse (was die Kunden angekreuzt haben, und wann)'],
        'batteries' => ['Contains batteries: show how to return old batteries on this product page', 'Enthält Batterien: auf dieser Produktseite zeigen, wie Altbatterien zurückgegeben werden'],
        'food_name' => ['Name of the food', 'Bezeichnung des Lebensmittels'],
        'food_ingredients' => ['Ingredients (mark allergens with stars, for example: sugar, *milk* powder; they show in bold)', 'Zutaten (Allergene mit Sternchen markieren, zum Beispiel: Zucker, *Milch*pulver; sie erscheinen fett)'],
        'food_allergens' => ['Allergens it contains', 'Enthaltene Allergene'],
        'food_traces' => ['May contain', 'Kann Spuren enthalten von'],
        'food_net' => ['Net quantity', 'Nettofüllmenge'],
        'food_drained' => ['Drained net weight', 'Abtropfgewicht'],
        'food_storage' => ['Storage and use', 'Aufbewahrung und Verwendung'],
        'food_origin' => ['Country of origin', 'Ursprungsland'],
        'food_alcohol' => ['Alcohol (% vol, only for drinks with more than 1.2 %)', 'Alkohol (% vol, nur für Getränke mit mehr als 1,2 %)'],
        'food_business' => ['Food business name and address (empty: the one in the Shopproof settings)', 'Name und Anschrift des Lebensmittelunternehmers (leer: der aus den Shopproof-Einstellungen)'],
        'food_nutriscore' => ['Nutri-Score', 'Nutri-Score'],
        'food_basis' => ['Nutrition values per', 'Nährwerte pro'],
        'food_energy_kj' => ['Energy (kJ)', 'Energie (kJ)'],
        'food_energy_kcal' => ['Energy (kcal; empty: worked out from kJ)', 'Energie (kcal; leer: aus kJ berechnet)'],
        'food_fat' => ['Fat (g)', 'Fett (g)'],
        'food_saturates' => ['of which saturates (g)', 'davon gesättigte Fettsäuren (g)'],
        'food_carbs' => ['Carbohydrate (g)', 'Kohlenhydrate (g)'],
        'food_sugars' => ['of which sugars (g)', 'davon Zucker (g)'],
        'food_fibre' => ['Fibre (g)', 'Ballaststoffe (g)'],
        'food_protein' => ['Protein (g)', 'Eiweiß (g)'],
        'food_salt' => ['Salt (g)', 'Salz (g)'],
        NotesCartProcessor::DESCRIPTION_FIELD => ['Description in the cart (one line under the name in the cart, at checkout and in the order, for example: Wool socks, size 42, dark blue)', 'Beschreibung im Warenkorb (eine Zeile unter dem Namen im Warenkorb, an der Kasse und in der Bestellung, zum Beispiel: Wollsocken, Größe 42, dunkelblau)'],
        PriceNotes::PREFIX . 'margin_scheme' => ['Margin scheme (second-hand goods): the tax line says VAT is included but not shown separately', 'Differenzbesteuerung (Gebrauchtwaren): die Steuerzeile sagt, dass die MwSt. enthalten, aber nicht ausgewiesen ist'],
        PriceNotes::PREFIX . 'defects' => ['Known defects (used or B-stock goods; shown below the price)', 'Bekannte Mängel (gebrauchte oder B-Ware; erscheinen unter dem Preis)'],
        Deposit::PREFIX . 'types' => ['Deposit types (codes from the Shopproof settings, comma separated, for example: de-single, at-single, de-crate)', 'Pfandarten (Codes aus den Shopproof-Einstellungen, mit Komma getrennt, zum Beispiel: de-einweg, at-einweg, de-kasten)'],
        Deposit::PREFIX . 'units' => ['Bottles or cans with a deposit (for example 6 for a six-pack; empty means 1)', 'Flaschen oder Dosen mit Pfand (zum Beispiel 6 für ein Sechserpack; leer heißt 1)'],
        'checks_text' => ['Ticked at checkout', 'An der Kasse angehakt'],
    ];

    /** Allergen names from Annex II of Regulation 1169/2011, English and German, for the product editor. */
    private const ALLERGEN_NAMES = [
        'gluten' => ['Cereals containing gluten', 'Glutenhaltiges Getreide'],
        'crustaceans' => ['Crustaceans', 'Krebstiere'],
        'eggs' => ['Eggs', 'Eier'],
        'fish' => ['Fish', 'Fisch'],
        'peanuts' => ['Peanuts', 'Erdnüsse'],
        'soybeans' => ['Soybeans', 'Soja'],
        'milk' => ['Milk', 'Milch'],
        'nuts' => ['Nuts', 'Schalenfrüchte'],
        'celery' => ['Celery', 'Sellerie'],
        'mustard' => ['Mustard', 'Senf'],
        'sesame' => ['Sesame seeds', 'Sesamsamen'],
        'sulphites' => ['Sulphur dioxide and sulphites', 'Schwefeldioxid und Sulphite'],
        'lupin' => ['Lupin', 'Lupinen'],
        'molluscs' => ['Molluscs', 'Weichtiere'],
    ];

    /** Pictogram names from CLP Annex V, English and German, for the product editor. */
    private const PICTOGRAM_NAMES = [
        'GHS01' => ['Exploding bomb', 'Explodierende Bombe'],
        'GHS02' => ['Flame', 'Flamme'],
        'GHS03' => ['Flame over circle', 'Flamme über einem Kreis'],
        'GHS04' => ['Gas cylinder', 'Gasflasche'],
        'GHS05' => ['Corrosion', 'Ätzwirkung'],
        'GHS06' => ['Skull and crossbones', 'Totenkopf mit gekreuzten Knochen'],
        'GHS07' => ['Exclamation mark', 'Ausrufezeichen'],
        'GHS08' => ['Health hazard', 'Gesundheitsgefahr'],
        'GHS09' => ['Environment', 'Umwelt'],
    ];

    private const MULTILINE = ['digital_consent', 'checks_text', 'manufacturer_address', 'responsible_address', 'identifiers', 'warnings', 'hazard_statements', 'precautionary_statements', 'food_ingredients', 'food_storage', 'food_business', PriceNotes::PREFIX . 'defects'];

    private const BOOLEAN = ['weee', 'batteries', PriceNotes::PREFIX . 'margin_scheme'];

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
            $this->set(self::HAZARD_SET, 'product', HazardLabel::PREFIX, HazardLabel::FIELDS, [
                'Shopproof: hazardous chemicals (CLP)',
                'Shopproof: gefährliche Chemikalien (CLP)',
            ]),
            $this->set(self::TAKEBACK_SET, 'product', Registrations::PREFIX, Registrations::SECTIONS, [
                'Shopproof: take-back of old devices and batteries',
                'Shopproof: Rücknahme von Altgeräten und Altbatterien',
            ]),
            $this->set(self::FOOD_SET, 'product', 'shopproof_', array_map(static fn (string $field): string => 'food_' . $field, FoodInformation::FIELDS), [
                'Shopproof: food information (food and drinks only)',
                'Shopproof: Lebensmittelinformationen (nur Lebensmittel und Getränke)',
            ]),
            $this->set(self::NOTES_SET, 'product', '', self::NOTES_FIELDS, [
                'Shopproof: price, deposit and cart notes',
                'Shopproof: Preis-, Pfand- und Warenkorbhinweise',
            ]),
            $this->set(self::ORDER_SET, 'order', 'shopproof_', ['digital_consent', 'checks_text'], [
                'Shopproof',
                'Shopproof',
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
     * Remove the stored values from products, manufacturers and orders. Used only when the merchant does
     * not keep the data on uninstall.
     */
    public function removeValues(): void
    {
        $keys = [
            'product_translation' => [
                ...array_map(static fn (string $field): string => SafetyData::PREFIX . $field, [...SafetyData::PARTY_FIELDS, ...SafetyData::PRODUCT_FIELDS]),
                ...array_map(static fn (string $field): string => DurabilityLabel::PREFIX . $field, DurabilityLabel::FIELDS),
                ...array_map(static fn (string $field): string => HazardLabel::PREFIX . $field, HazardLabel::FIELDS),
                ...array_map(static fn (string $field): string => Registrations::PREFIX . $field, Registrations::SECTIONS),
                ...array_map(static fn (string $field): string => FoodInformation::PREFIX . $field, FoodInformation::FIELDS),
                ...self::NOTES_FIELDS,
            ],
            'product_manufacturer_translation' => array_map(
                static fn (string $field): string => SafetyData::MANUFACTURER_PREFIX . $field,
                SafetyData::MANUFACTURER_FIELDS
            ),
            'order' => ['shopproof_digital_consent', CheckoutBoxes::ORDER_FIELD, CheckoutBoxes::ORDER_TEXT_FIELD],
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
                        ['value' => 'nested', 'label' => ['en-GB' => 'Short strip that opens the full label (default)', 'de-DE' => 'Schmaler Streifen, der das ganze Label öffnet (Standard)']],
                        ['value' => 'full', 'label' => ['en-GB' => 'Full label', 'de-DE' => 'Ganzes Label']],
                    ],
                ];
            }
            if ($field === 'hazard_pictograms') {
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-multi-select',
                    'customFieldType' => 'select',
                    'options' => array_map(static fn (string $code): array => [
                        'value' => $code,
                        'label' => ['en-GB' => $code . ' ' . self::PICTOGRAM_NAMES[$code][0], 'de-DE' => $code . ' ' . self::PICTOGRAM_NAMES[$code][1]],
                    ], HazardLabel::PICTOGRAMS),
                ];
            }
            if ($field === 'hazard_signal') {
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'options' => [
                        ['value' => 'danger', 'label' => ['en-GB' => 'Danger', 'de-DE' => 'Gefahr']],
                        ['value' => 'warning', 'label' => ['en-GB' => 'Warning', 'de-DE' => 'Achtung']],
                    ],
                ];
            }
            if ($field === 'food_allergens') {
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-multi-select',
                    'customFieldType' => 'select',
                    'options' => array_map(static fn (string $code): array => [
                        'value' => $code,
                        'label' => ['en-GB' => self::ALLERGEN_NAMES[$code][0], 'de-DE' => self::ALLERGEN_NAMES[$code][1]],
                    ], FoodInformation::ALLERGENS),
                ];
            }
            if ($field === 'food_nutriscore' || $field === 'food_basis') {
                $choices = $field === 'food_basis' ? FoodInformation::BASES : FoodInformation::NUTRI_SCORES;
                $type = CustomFieldTypes::SELECT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-single-select',
                    'customFieldType' => 'select',
                    'options' => array_map(static fn (string $choice): array => ['value' => $choice, 'label' => ['en-GB' => $choice, 'de-DE' => $choice]], $choices),
                ];
            }
            if (str_starts_with($field, 'food_') && \in_array(substr($field, 5), FoodInformation::NUMBERS, true)) {
                $type = CustomFieldTypes::FLOAT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'type' => 'number',
                    'numberType' => 'float',
                    'min' => 0,
                ];
            }
            if ($field === Deposit::PREFIX . 'units') {
                $type = CustomFieldTypes::INT;
                $config = [
                    ...$config,
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'type' => 'number',
                    'numberType' => 'int',
                    'min' => 1,
                    'max' => 999,
                ];
            }
            if (\in_array($field, self::BOOLEAN, true)) {
                $type = CustomFieldTypes::BOOL;
                $config = [
                    ...$config,
                    'componentName' => 'sw-field',
                    'customFieldType' => 'checkbox',
                    'type' => 'checkbox',
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
