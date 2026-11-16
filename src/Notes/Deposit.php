<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * Bottle deposit (in Germany PAngV § 7: the deposit is stated next to the price, not added into
 * it). The shop lists its deposit types once in the plugin settings, one per line:
 * "code | name | amount | kind | country", for example "de-single | Einweg | 0.25 | single | DE".
 * A product names its types (bottle or can, and crate) in the custom field set "Shopproof: price
 * and deposit" and the number of containers. Shoppers whose shipping country is a type's country
 * see "plus EUR 0.25 deposit" below the price, and the cart adds the deposit as its own line;
 * shoppers in other countries see nothing. Same types, wording and country rule as the Shopify app
 * and the WooCommerce plugin. Pure, unit tested alone.
 */
final class Deposit
{
    public const PREFIX = 'shopproof_deposit_';

    public const FIELDS = ['types', 'units'];

    public const KINDS = ['single', 'reusable', 'crate'];

    /** Kind words a shop may type, in the languages of the launch countries. */
    private const KIND_WORDS = [
        'single' => 'single', 'single-use' => 'single', 'einweg' => 'single', 'usage unique' => 'single', 'monouso' => 'single', 'eenmalig' => 'single',
        'reusable' => 'reusable', 'mehrweg' => 'reusable', 'réutilisable' => 'reusable', 'reutilisable' => 'reusable', 'riutilizzabile' => 'reusable', 'herbruikbaar' => 'reusable',
        'crate' => 'crate', 'kasten' => 'crate', 'kiste' => 'crate', 'caisse' => 'crate', 'cassa' => 'crate', 'krat' => 'crate',
    ];

    /** Countries a type can be set for: the EU and EEA plus CH and GB, as in the Shopify app. */
    public const COUNTRIES = ['AT', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR', 'HR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

    public const MAX_TYPES = 50;

    /** The name of the cart line in the shopper's language, the word of the "plus ... deposit" text. */
    public const LINE_NAME = ['en' => 'Deposit', 'de' => 'Pfand', 'fr' => 'Consigne', 'it' => 'Cauzione', 'nl' => 'Statiegeld'];

    /**
     * The shop's deposit types from the settings text, and the lines that could not be read.
     *
     * @return array{types: array<string, array{name: string, amount: float, kind: string, country: string}>, bad: list<string>}
     */
    public static function parseTypes(string $text): array
    {
        $types = [];
        $bad = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $code = self::code($parts[0]);
            $name = FoodInformation::plain($parts[1] ?? '', 60);
            $amount = str_replace(',', '.', $parts[2] ?? '');
            $kind = self::KIND_WORDS[mb_strtolower($parts[3] ?? '')] ?? '';
            $country = strtoupper($parts[4] ?? '');
            if (\count($parts) !== 5 || $code === '' || $name === '' || preg_match('/^\d{1,3}(\.\d{1,2})?$/', $amount) !== 1 || (float) $amount <= 0
                || $kind === '' || !\in_array($country, self::COUNTRIES, true) || isset($types[$code]) || \count($types) >= self::MAX_TYPES) {
                $bad[] = mb_substr($line, 0, 120);
                continue;
            }
            $types[$code] = ['name' => $name, 'amount' => round((float) $amount, 2), 'kind' => $kind, 'country' => $country];
        }

        return ['types' => $types, 'bad' => $bad];
    }

    /**
     * A type code: lower case letters, digits and dashes.
     */
    public static function code(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim($value))), '-');
    }

    /**
     * The deposit of one item for shoppers in a country. A product names its bottle and crate
     * types in one field ("de-single, at-single, de-crate"); of each, the one for the country
     * counts. Shopware merges a variant's custom fields over its main product's, so a variant that
     * only sets its number of containers keeps the main product's types.
     *
     * @param array<mixed>|null                                                     $customFields
     * @param array<string, array{name: string, amount: float, kind: string, country: string}> $types
     *
     * @return array{total: float, each: float, units: int, kind: string, crate: float}
     */
    public static function forProduct(?array $customFields, array $types, string $country): array
    {
        $fields = $customFields ?? [];
        $raw = $fields[self::PREFIX . 'types'] ?? '';
        $codes = \is_string($raw) ? explode(',', $raw) : (\is_array($raw) ? $raw : []);
        $bottle = null;
        $crate = null;
        foreach ($codes as $code) {
            $type = \is_string($code) ? ($types[self::code($code)] ?? null) : null;
            if ($type === null || $type['country'] !== strtoupper($country)) {
                continue;
            }
            if ($type['kind'] === 'crate') {
                $crate ??= $type;
            } else {
                $bottle ??= $type;
            }
        }
        $units = $fields[self::PREFIX . 'units'] ?? null;
        $units = \is_int($units) || (\is_string($units) && ctype_digit($units)) || (\is_float($units) && floor($units) === $units) ? (int) $units : 1;
        $units = max(1, min(999, $units));
        $each = $bottle !== null ? $bottle['amount'] : 0.0;
        $box = $crate !== null ? $crate['amount'] : 0.0;

        return [
            'total' => round($each * $units + $box, 2),
            'each' => $each,
            'units' => $units,
            'kind' => $bottle !== null ? $bottle['kind'] : '',
            'crate' => $box,
        ];
    }

    /**
     * "plus EUR 1.50 deposit (6 × EUR 0.25 single-use)" for the product page and listings, or ''
     * without a deposit. Prices that already hold the deposit say "Included deposit: ..." instead,
     * so shoppers never expect to pay more. Plain text; the caller escapes it.
     *
     * @param array{total: float, each: float, units: int, kind: string, crate: float} $deposit
     * @param \Closure(float): string                                                 $money
     */
    public static function note(array $deposit, bool $inPrice, NotesTexts $texts, string $lang, \Closure $money): string
    {
        if ($deposit['total'] <= 0) {
            return '';
        }
        $detail = [];
        if ($deposit['each'] > 0) {
            $detail[] = ($deposit['units'] > 1 ? $deposit['units'] . " \u{00D7} " : '') . $money($deposit['each']) . ' ' . $texts->text('deposit.' . $deposit['kind'], $lang);
        }
        if ($deposit['crate'] > 0) {
            $detail[] = $texts->text('deposit.crate', $lang) . ' ' . $money($deposit['crate']);
        }

        return $texts->text($inPrice ? 'deposit.cart_in' : 'deposit.plus', $lang, ['amount' => $money($deposit['total'])]) . ' (' . implode(', ', $detail) . ')';
    }
}
