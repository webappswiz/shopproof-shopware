<?php declare(strict_types=1);

namespace Shopproof\Compliance\Storefront;

/**
 * Works out the product safety (GPSR) information for one product, Regulation (EU) 2023/988
 * Art. 19 (rule EU-PS-01). Each party field comes from the first place that has it: the product,
 * then its manufacturer in Shopware, then the shop-wide defaults in the plugin settings. Pure, so it
 * is unit tested without Shopware.
 */
final class SafetyData
{
    /** Custom field prefix on products. */
    public const PREFIX = 'shopproof_safety_';

    /** Custom field prefix on manufacturers (custom field names are unique across entities). */
    public const MANUFACTURER_PREFIX = 'shopproof_mfr_';

    /** Party fields, also used as custom field names after PREFIX and as setting names. */
    public const PARTY_FIELDS = [
        'manufacturer_name', 'manufacturer_address', 'manufacturer_email', 'manufacturer_url',
        'responsible_name', 'responsible_address', 'responsible_email', 'responsible_url',
    ];

    /** Fields set only per product. */
    public const PRODUCT_FIELDS = ['identifiers', 'warnings'];

    /** Party fields a Shopware manufacturer can carry. Its name comes from the manufacturer itself. */
    public const MANUFACTURER_FIELDS = [
        'manufacturer_address', 'manufacturer_email', 'manufacturer_url',
        'responsible_name', 'responsible_address', 'responsible_email', 'responsible_url',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<mixed>|null $productFields      the product's custom fields
     * @param string|null       $manufacturerName   name of the product's Shopware manufacturer
     * @param array<mixed>|null $manufacturerFields the manufacturer's custom fields
     * @param array<mixed>      $defaults           shop-wide defaults keyed by party field name
     *
     * @return array{
     *     manufacturer: array{name: string, address: string, email: string, url: string}|null,
     *     responsiblePerson: array{name: string, address: string, email: string, url: string}|null,
     *     identifiers: list<array{label: string, value: string}>,
     *     warnings: string
     * }|null null when there is no manufacturer name, because the block would not be complete
     */
    public static function resolve(?array $productFields, ?string $manufacturerName, ?array $manufacturerFields, array $defaults): ?array
    {
        $values = [];
        foreach (self::PARTY_FIELDS as $field) {
            $values[$field] = self::first([
                self::field($productFields, self::PREFIX . $field),
                $field === 'manufacturer_name' ? self::clean($manufacturerName) : self::field($manufacturerFields, self::MANUFACTURER_PREFIX . $field),
                self::clean($defaults[$field] ?? null),
            ]);
        }

        if ($values['manufacturer_name'] === '') {
            return null;
        }

        return [
            'manufacturer' => self::party($values, 'manufacturer'),
            'responsiblePerson' => self::party($values, 'responsible'),
            'identifiers' => self::identifiers(self::field($productFields, self::PREFIX . 'identifiers')),
            'warnings' => self::field($productFields, self::PREFIX . 'warnings'),
        ];
    }

    /**
     * One identifier per line, "Label: value" or just a value.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function identifiers(string $text): array
    {
        $items = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line, 2);
            if (\count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                $items[] = ['label' => trim($parts[0]), 'value' => trim($parts[1])];
            } else {
                $items[] = ['label' => '', 'value' => $line];
            }
        }

        return $items;
    }

    /**
     * @param array<string, string> $values
     *
     * @return array{name: string, address: string, email: string, url: string}|null
     */
    private static function party(array $values, string $who): ?array
    {
        if ($values[$who . '_name'] === '') {
            return null;
        }
        $email = $values[$who . '_email'];
        $url = $values[$who . '_url'];

        return [
            'name' => $values[$who . '_name'],
            'address' => $values[$who . '_address'],
            'email' => filter_var($email, \FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'url' => preg_match('#^https?://[^\s]+$#i', $url) === 1 ? $url : '',
        ];
    }

    /**
     * @param array<mixed>|null $fields
     */
    private static function field(?array $fields, string $key): string
    {
        return \is_array($fields) ? self::clean($fields[$key] ?? null) : '';
    }

    private static function clean(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }

    /**
     * @param list<string> $candidates
     */
    private static function first(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}
