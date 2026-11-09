<?php declare(strict_types=1);

namespace Shopproof\Compliance\Storefront;

/**
 * Works out the product safety (GPSR) information for one product, Regulation (EU) 2023/988
 * Art. 19 (rule EU-PS-01), from the product, its manufacturer in Shopware and the shop-wide
 * defaults in the plugin settings (see resolve()). Pure, so it is unit tested without Shopware.
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

    private const PARTIES = ['manufacturer', 'responsible'];

    private function __construct()
    {
    }

    /**
     * Each party (manufacturer, EU responsible person) comes whole from one place, so a name is
     * never shown with someone else's address.
     *
     * The manufacturer's name is the product's own entry, else the Shopware manufacturer's name,
     * else the shop-wide default. Both parties then come from the Shopware manufacturer when it is
     * the one named and has Shopproof details for that party, else from the shop-wide defaults
     * when the default name is the one named, else from nowhere (the manufacturer then shows its
     * name only, and the setup check lists it). Fields set on the product itself fill in or
     * replace single fields on top. A responsible person the product names differently takes
     * nothing from elsewhere.
     *
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
        $product = self::values($productFields, self::PREFIX, self::PARTY_FIELDS);
        $shopware = self::values($manufacturerFields, self::MANUFACTURER_PREFIX, self::MANUFACTURER_FIELDS);
        $shopware['manufacturer_name'] = self::clean($manufacturerName);
        $default = self::values($defaults, '', self::PARTY_FIELDS);

        $name = self::first([$product['manufacturer_name'], $shopware['manufacturer_name'], $default['manufacturer_name']]);
        if ($name === '') {
            return null;
        }
        $isShopware = $shopware['manufacturer_name'] !== '' && self::sameName($name, $shopware['manufacturer_name']);
        $isDefault = $default['manufacturer_name'] !== '' && self::sameName($name, $default['manufacturer_name']);

        $values = [];
        foreach (self::PARTIES as $who) {
            if ($isShopware && self::hasDetails($shopware, $who)) {
                $base = $shopware;
            } elseif ($isDefault) {
                $base = $default;
            } else {
                $base = array_fill_keys(self::PARTY_FIELDS, '');
            }
            if ($who === 'responsible' && $product['responsible_name'] !== '' && !self::sameName($product['responsible_name'], $base['responsible_name'])) {
                $base = array_fill_keys(self::PARTY_FIELDS, '');
            }
            foreach (self::fieldsOf($who) as $field) {
                $values[$field] = self::first([$product[$field], $base[$field]]);
            }
        }
        $values['manufacturer_name'] = $name;

        return [
            'manufacturer' => self::party($values, 'manufacturer'),
            'responsiblePerson' => self::party($values, 'responsible'),
            'identifiers' => self::identifiers(self::field($productFields, self::PREFIX . 'identifiers')),
            'warnings' => self::field($productFields, self::PREFIX . 'warnings'),
        ];
    }

    /**
     * Whether products of this Shopware manufacturer get contact details for it: from its own
     * Shopproof fields, or from the shop-wide defaults when the default name is this manufacturer's
     * name. The setup check lists the manufacturers for which this is false.
     *
     * @param array<mixed>|null $manufacturerFields the manufacturer's custom fields
     * @param array<mixed>      $defaults           shop-wide defaults keyed by party field name
     */
    public static function manufacturerHasDetails(string $manufacturerName, ?array $manufacturerFields, array $defaults): bool
    {
        if (self::hasDetails(self::values($manufacturerFields, self::MANUFACTURER_PREFIX, self::MANUFACTURER_FIELDS), 'manufacturer')) {
            return true;
        }
        $default = self::values($defaults, '', self::PARTY_FIELDS);

        return $default['manufacturer_name'] !== ''
            && self::sameName($manufacturerName, $default['manufacturer_name'])
            && self::hasDetails($default, 'manufacturer');
    }

    /**
     * Names match when they differ only in case or spacing.
     */
    public static function sameName(string $a, string $b): bool
    {
        $normal = static fn (string $name): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));

        return $normal($a) === $normal($b);
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
     * The given fields of a source, keyed by party field name; every party field is present and
     * '' where the source does not have it.
     *
     * @param array<mixed>|null $fields
     * @param list<string>      $names
     *
     * @return array<string, string>
     */
    private static function values(?array $fields, string $prefix, array $names): array
    {
        $values = array_fill_keys(self::PARTY_FIELDS, '');
        foreach ($names as $name) {
            $values[$name] = self::field($fields, $prefix . $name);
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private static function fieldsOf(string $who): array
    {
        return [$who . '_name', $who . '_address', $who . '_email', $who . '_url'];
    }

    /**
     * True when a source has something for the party: a contact detail for the manufacturer, any
     * field for the responsible person.
     *
     * @param array<string, string> $values
     */
    private static function hasDetails(array $values, string $who): bool
    {
        $fields = $who === 'manufacturer' ? ['manufacturer_address', 'manufacturer_email', 'manufacturer_url'] : self::fieldsOf($who);
        foreach ($fields as $field) {
            if (($values[$field] ?? '') !== '') {
                return true;
            }
        }

        return false;
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
