<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * Food information (Regulation (EU) No 1169/2011, Art. 9 and 14): the food details shoppers must
 * see before they buy, in a "Food information" tab next to the product description, as food shops
 * usually show them. Per-product values sit in the custom field set "Shopproof: food information";
 * the food business defaults to the one in the plugin settings. Nothing shows on a product
 * without food values, and no value is ever made up. Same fields, wording and EU rounding as the
 * Shopify app's Food information block and the WooCommerce plugin. Pure, unit tested alone.
 */
final class FoodInformation
{
    public const PREFIX = 'shopproof_food_';

    /** Text fields: name => [most characters, multi line]. */
    public const TEXT_FIELDS = [
        'name' => [200, false],
        'ingredients' => [3000, true],
        'traces' => [300, false],
        'net' => [60, false],
        'drained' => [60, false],
        'storage' => [1000, true],
        'origin' => [200, false],
        'business' => [500, true],
    ];

    /** Nutrients in the order of Annex XV, in grams per 100 g or 100 ml. */
    public const NUTRIENTS = ['fat', 'saturates', 'carbs', 'sugars', 'fibre', 'protein', 'salt'];

    /** The 14 allergens of Annex II. */
    public const ALLERGENS = ['gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soybeans', 'milk', 'nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs'];

    public const NUTRI_SCORES = ['A', 'B', 'C', 'D', 'E'];

    public const BASES = ['100 g', '100 ml'];

    /** Every custom field of the set, in the order of the product editor. */
    public const FIELDS = ['name', 'ingredients', 'allergens', 'traces', 'net', 'drained', 'storage', 'origin', 'alcohol', 'business', 'nutriscore', 'basis', 'energy_kj', 'energy_kcal', 'fat', 'saturates', 'carbs', 'sugars', 'fibre', 'protein', 'salt'];

    /** Fields that hold a number. */
    public const NUMBERS = ['alcohol', 'energy_kj', 'energy_kcal', 'fat', 'saturates', 'carbs', 'sugars', 'fibre', 'protein', 'salt'];

    /**
     * The food values from a product's custom fields: every field as a string, '' when empty;
     * allergens as a list of known codes.
     *
     * @param array<mixed>|null $customFields
     *
     * @return array{values: array<string, string>, allergens: list<string>}
     */
    public static function values(?array $customFields): array
    {
        $fields = $customFields ?? [];
        $values = [];
        foreach (self::TEXT_FIELDS as $field => [$max, $multiline]) {
            $values[$field] = self::plain($fields[self::PREFIX . $field] ?? null, $max, $multiline);
        }
        foreach (self::NUMBERS as $field) {
            $values[$field] = self::cleanNumber($fields[self::PREFIX . $field] ?? null);
        }
        $score = $fields[self::PREFIX . 'nutriscore'] ?? null;
        $values['nutriscore'] = \is_string($score) && \in_array(strtoupper($score), self::NUTRI_SCORES, true) ? strtoupper($score) : '';
        $basis = $fields[self::PREFIX . 'basis'] ?? null;
        $values['basis'] = \is_string($basis) && \in_array($basis, self::BASES, true) ? $basis : '';

        $raw = $fields[self::PREFIX . 'allergens'] ?? [];
        if (\is_string($raw)) {
            $raw = explode(',', $raw);
        }
        $chosen = [];
        foreach (\is_array($raw) ? $raw : [] as $code) {
            if (\is_string($code)) {
                $chosen[] = trim($code);
            }
        }
        $allergens = array_values(array_intersect(self::ALLERGENS, $chosen));

        return ['values' => $values, 'allergens' => $allergens];
    }

    /**
     * Whether the product has any food value. The shop's default food business alone does not
     * count, or every product of a shop would show a food tab. Nor does the basis alone.
     *
     * @param array{values: array<string, string>, allergens: list<string>} $food
     */
    public static function hasContent(array $food): bool
    {
        if ($food['allergens'] !== []) {
            return true;
        }
        foreach ($food['values'] as $field => $value) {
            if ($field !== 'basis' && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything the template needs, already in the shopper's language, or null when the product
     * has no food values. Values are plain text; the template escapes them.
     *
     * @param array{values: array<string, string>, allergens: list<string>} $food
     *
     * @return array{heading: string, rows: list<array{label: string, text?: string, parts?: list<array{text: string, bold: bool}>}>, nutrition: array{caption: string, average: string, basis: string, rows: list<array{label: string, value: string, sub: bool}>}|null}|null
     */
    public static function block(array $food, string $defaultBusiness, NotesTexts $texts, string $lang): ?array
    {
        if (!self::hasContent($food)) {
            return null;
        }
        $v = $food['values'];
        $t = static fn (string $key): string => $texts->text($key, $lang);
        $rows = [];
        if ($v['name'] !== '') {
            $rows[] = ['label' => $t('food.name'), 'text' => $v['name']];
        }
        if ($v['ingredients'] !== '') {
            $rows[] = ['label' => $t('food.ingredients'), 'parts' => self::ingredientParts($v['ingredients'])];
        }
        if ($food['allergens'] !== []) {
            $names = array_map(static fn (string $code): string => $t('food.a.' . $code), $food['allergens']);
            $rows[] = ['label' => $t('food.contains'), 'text' => implode(', ', $names)];
        }
        foreach (['traces', 'net', 'drained', 'storage', 'origin'] as $field) {
            if ($v[$field] !== '') {
                $rows[] = ['label' => $t('food.' . $field), 'text' => $v[$field]];
            }
        }
        if ($v['alcohol'] !== '') {
            $rows[] = ['label' => $t('food.alcohol'), 'text' => str_replace('.', self::decimalSign($lang), $v['alcohol']) . ' % vol'];
        }
        $business = $v['business'] !== '' ? $v['business'] : self::plain($defaultBusiness, 500, true);
        if ($business !== '') {
            $rows[] = ['label' => $t('food.business'), 'text' => $business];
        }
        if ($v['nutriscore'] !== '') {
            $rows[] = ['label' => 'Nutri-Score', 'text' => $v['nutriscore']];
        }

        return [
            'heading' => $t('food.heading'),
            'rows' => $rows,
            'nutrition' => self::nutrition($v, $texts, $lang),
        ];
    }

    /**
     * The nutrition declaration, or null when no nutrient is filled in.
     *
     * @param array<string, string> $v
     *
     * @return array{caption: string, average: string, basis: string, rows: list<array{label: string, value: string, sub: bool}>}|null
     */
    private static function nutrition(array $v, NotesTexts $texts, string $lang): ?array
    {
        $any = $v['energy_kj'] !== '' || $v['energy_kcal'] !== '';
        foreach (self::NUTRIENTS as $nutrient) {
            $any = $any || $v[$nutrient] !== '';
        }
        if (!$any) {
            return null;
        }
        $basis = $v['basis'] !== '' ? $v['basis'] : '100 g';
        $kj = $v['energy_kj'] !== '' ? (float) $v['energy_kj'] : null;
        $kcal = $v['energy_kcal'] !== '' ? (float) $v['energy_kcal'] : ($kj !== null ? $kj / 4.184 : null);
        $rows = [];
        if ($kcal !== null) {
            $rows[] = [
                'label' => $texts->text('food.n.energy', $lang),
                'value' => ($kj !== null ? round($kj) . ' kJ / ' : '') . round($kcal) . ' kcal',
                'sub' => false,
            ];
        }
        foreach (self::NUTRIENTS as $nutrient) {
            if ($v[$nutrient] === '') {
                continue;
            }
            $rows[] = [
                'label' => $texts->text('food.n.' . $nutrient, $lang),
                'value' => self::roundNutrient($v[$nutrient], $nutrient, $lang) . ' g',
                'sub' => $nutrient === 'saturates' || $nutrient === 'sugars',
            ];
        }

        return [
            'caption' => $texts->text('food.nutrition', $lang, ['basis' => $basis]),
            'average' => $texts->text('food.average', $lang),
            'basis' => $basis,
            'rows' => $rows,
        ];
    }

    /**
     * A nutrient in grams, rounded as the EU rounding guidance (2012) asks.
     */
    public static function roundNutrient(string $value, string $nutrient, string $lang = 'en'): string
    {
        $x = (float) str_replace(',', '.', $value);
        $low = 0.5;
        $less = '<0.5';
        $high = 10.0;
        $places = 1;
        if ($nutrient === 'saturates') {
            $low = 0.1;
            $less = '<0.1';
        } elseif ($nutrient === 'salt') {
            $low = 0.0125;
            $less = '<0.01';
            $high = 1.0;
            $places = 2;
        }
        if ($x <= 0) {
            $out = '0';
        } elseif ($x <= $low) {
            $out = $less;
        } else {
            $rounded = round($x, $places);
            if ($rounded >= $high) {
                $rounded = $nutrient === 'salt' ? round($x, 1) : round($x);
            }
            $out = self::number($rounded);
        }

        return str_replace('.', self::decimalSign($lang), $out);
    }

    /**
     * The ingredients split at the stars around allergens: odd parts are allergens, in bold.
     *
     * @return list<array{text: string, bold: bool}>
     */
    public static function ingredientParts(string $text): array
    {
        $parts = [];
        foreach (explode('*', $text) as $i => $part) {
            if ($part !== '') {
                $parts[] = ['text' => $part, 'bold' => $i % 2 === 1];
            }
        }

        return $parts;
    }

    /**
     * A non-negative number (comma or point, or a number from a number field) as text without
     * trailing zeros, or '' when it is not one.
     */
    public static function cleanNumber(mixed $raw): string
    {
        if (\is_int($raw) || \is_float($raw)) {
            return $raw >= 0 && $raw < 1000000 ? self::number((float) $raw) : '';
        }
        if (!\is_string($raw)) {
            return '';
        }
        $text = str_replace(',', '.', trim($raw));
        if (preg_match('/^\d{1,6}(\.\d{1,4})?$/', $text) !== 1) {
            return '';
        }

        return self::number((float) $text);
    }

    public static function decimalSign(string $lang): string
    {
        return $lang === 'en' ? '.' : ',';
    }

    /**
     * Text only: no markup or control characters, trimmed and capped.
     */
    public static function plain(mixed $value, int $max, bool $multiline = false): string
    {
        if (!\is_string($value)) {
            return '';
        }
        $text = strip_tags($value);
        $text = (string) preg_replace($multiline ? '/[^\P{C}\n]+/u' : '/\p{C}+/u', ' ', str_replace("\r\n", "\n", $text));
        $text = trim($multiline ? (string) preg_replace("/[ \t]+/", ' ', $text) : (string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, $max);
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }
}
