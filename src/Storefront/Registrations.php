<?php declare(strict_types=1);

namespace Shopproof\Compliance\Storefront;

/**
 * The shop's own registration numbers and the take-back information for old batteries and
 * electrical equipment (decision 0018). The merchant types the numbers in the plugin settings,
 * one per line, and marks products that are electrical equipment or contain batteries. Shopproof
 * checks no registers. Shopper text comes from takeback.json, built from the reviewed rules data.
 * Same behaviour as the WooCommerce plugin's Shopproof_Registrations.
 */
final class Registrations
{
    /** Product custom fields: "Electrical equipment" and "Contains batteries". */
    public const PREFIX = 'shopproof_takeback_';

    public const SECTIONS = ['weee', 'batteries'];

    public const MAX_NUMBERS = 12;

    public const COUNTRIES = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO'];

    /** Words the merchant may write for each kind, in lower case. */
    private const KIND_WORDS = [
        'weee' => ['weee', 'elektrog', 'ear', 'electrical', 'elektro'],
        'batteries' => ['batteries', 'battery', 'batterien', 'batteriegesetz', 'battdg', 'piles', 'batterie'],
        'packaging' => ['packaging', 'verpackung', 'verpackungen', 'lucid', 'emballages', 'imballaggi', 'verpakking'],
        'epr' => ['idu', 'epr', 'rep'],
        'textiles' => ['textiles', 'textile', 'textilien', 'tessili', 'textiel'],
    ];

    /** @var array<mixed>|null */
    private ?array $data = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * Registration numbers from the settings text, one per line: "WEEE DE: DE 12345678",
     * "IDU FR: FR123456_01ABCD" or "Own label: number". The first word names the kind (WEEE,
     * Batteries, Packaging, IDU, Textiles); a two letter EU or EEA country code may follow. Any
     * other text before the colon is the merchant's own label. Lines without a colon or a number
     * are left out (the Shopproof page lists them).
     *
     * @return list<array{kind: string, country: string, number: string, label: string}>
     */
    public static function parseNumbers(string $text): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $row = self::parseLine($line);
            if ($row !== null && \count($rows) < self::MAX_NUMBERS) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Lines of the settings text that are not used, for the Shopproof page.
     *
     * @return list<string>
     */
    public static function badLines(string $text): array
    {
        $bad = [];
        $used = 0;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (self::parseLine($line) === null || ++$used > self::MAX_NUMBERS) {
                $bad[] = mb_substr(trim($line), 0, 120);
            }
        }

        return $bad;
    }

    /**
     * @return array{kind: string, country: string, number: string, label: string}|null
     */
    private static function parseLine(string $line): ?array
    {
        $parts = explode(':', $line, 2);
        if (\count($parts) !== 2) {
            return null;
        }
        $label = trim((string) preg_replace('/\s+/u', ' ', $parts[0]));
        $number = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $parts[1])), 0, 80);
        if ($label === '' || $number === '') {
            return null;
        }
        $words = explode(' ', $label);
        $kind = '';
        foreach (self::KIND_WORDS as $key => $names) {
            if (\in_array(mb_strtolower($words[0]), $names, true)) {
                $kind = $key;
            }
        }
        $country = '';
        if ($kind !== '' && \count($words) <= 2) {
            $code = strtoupper($words[1] ?? '');
            if ($code === '' || \in_array($code, self::COUNTRIES, true)) {
                $country = $code;
            } else {
                $kind = '';
            }
        } elseif ($kind !== '') {
            $kind = '';
        }

        return [
            'kind' => $kind !== '' ? $kind : 'other',
            'country' => $country,
            'number' => $number,
            'label' => $kind !== '' ? '' : mb_substr($label, 0, 80),
        ];
    }

    /**
     * Take-back sections a product shows: in 'all' mode those the shop turned on, else those the
     * product is marked with.
     *
     * @param array<mixed>|null    $fields   the product's custom fields
     * @param array<string, bool>  $shopOn   'weee' and 'batteries' switches from the settings
     *
     * @return list<string>
     */
    public static function sectionsForProduct(?array $fields, string $mode, array $shopOn): array
    {
        if ($mode === 'all') {
            return array_values(array_filter(self::SECTIONS, static fn (string $s): bool => ($shopOn[$s] ?? false) === true));
        }

        return array_values(array_filter(
            self::SECTIONS,
            static fn (string $s): bool => \is_array($fields) && ($fields[self::PREFIX . $s] ?? null) === true
        ));
    }

    /**
     * Everything the template needs, or null when there is nothing to show.
     *
     * @param list<array{kind: string, country: string, number: string, label: string}> $rows
     * @param list<string>                                                               $sections
     *
     * @return array{lang: string, heading: string, numbers: list<array{label: string, number: string}>, sections: list<array{key: string, heading: string, text: list<string>}>, symbolAlt: string, returnHeading: string, note: string}|null
     */
    public function block(array $rows, array $sections, bool $oneForOne, string $note, string $localeCode, string $fallback): ?array
    {
        $sections = array_values(array_intersect(self::SECTIONS, $sections));
        if ($rows === [] && $sections === []) {
            return null;
        }
        $lang = $this->language($localeCode, $fallback);
        $out = [];
        foreach ($sections as $section) {
            $text = $this->paragraphs($lang, $section);
            if ($section === 'weee' && $oneForOne) {
                $text[] = $this->string($lang, 'weee.one_for_one');
            }
            $out[] = ['key' => $section, 'heading' => $this->string($lang, $section . '.heading'), 'text' => $text];
        }

        return [
            'lang' => $lang,
            'heading' => $this->string($lang, 'registrations'),
            'numbers' => $this->labelled($rows, $lang),
            'sections' => $out,
            'symbolAlt' => $this->string($lang, 'symbol_alt'),
            'returnHeading' => $this->string($lang, 'return_to_us'),
            'note' => $sections !== [] ? trim($note) : '',
        ];
    }

    /**
     * The numbers as one line for the footer, or null when there are none.
     *
     * @param list<array{kind: string, country: string, number: string, label: string}> $rows
     *
     * @return array{lang: string, numbers: list<array{label: string, number: string}>}|null
     */
    public function footer(array $rows, string $localeCode, string $fallback): ?array
    {
        if ($rows === []) {
            return null;
        }
        $lang = $this->language($localeCode, $fallback);

        return ['lang' => $lang, 'numbers' => $this->labelled($rows, $lang)];
    }

    /**
     * The shopper-facing label of a row, with its country when set: "WEEE-Reg.-Nr. (DE)", and
     * "Unique identifier (IDU, FR)" rather than "(IDU) (FR)".
     *
     * @param array{kind: string, country: string, number: string, label: string} $row
     */
    public function rowLabel(array $row, string $lang): string
    {
        $label = $row['kind'] === 'other' ? $row['label'] : $this->string($lang, 'kinds.' . $row['kind']);
        if ($row['country'] === '') {
            return $label;
        }

        return str_ends_with($label, ')') ? substr($label, 0, -1) . ', ' . $row['country'] . ')' : $label . ' (' . $row['country'] . ')';
    }

    /**
     * @param list<array{kind: string, country: string, number: string, label: string}> $rows
     *
     * @return list<array{label: string, number: string}>
     */
    private function labelled(array $rows, string $lang): array
    {
        return array_map(fn (array $row): array => ['label' => $this->rowLabel($row, $lang), 'number' => $row['number']], $rows);
    }

    private function language(string $localeCode, string $fallback): string
    {
        $text = $this->texts();
        $page = strtolower(substr($localeCode, 0, 2));
        if (isset($text[$page])) {
            return $page;
        }

        return isset($text[$fallback]) ? $fallback : 'en';
    }

    /**
     * @return list<string>
     */
    private function paragraphs(string $lang, string $section): array
    {
        $entry = $this->texts()[$lang] ?? null;
        $part = \is_array($entry) ? ($entry[$section] ?? null) : null;
        $node = \is_array($part) ? ($part['text'] ?? null) : null;

        return \is_array($node) ? array_values(array_filter($node, static fn (mixed $line): bool => \is_string($line) && $line !== '')) : [];
    }

    private function string(string $lang, string $path): string
    {
        $node = $this->texts()[$lang] ?? null;
        foreach (explode('.', $path) as $key) {
            if (!\is_array($node) || !isset($node[$key])) {
                return '';
            }
            $node = $node[$key];
        }

        return \is_string($node) ? $node : '';
    }

    /**
     * @return array<mixed>
     */
    private function texts(): array
    {
        if ($this->data === null) {
            $raw = is_file($this->file) ? file_get_contents($this->file) : false;
            $decoded = \is_string($raw) ? json_decode($raw, true) : null;
            $this->data = \is_array($decoded) && \is_array($decoded['text'] ?? null) ? $decoded['text'] : [];
        }

        return $this->data;
    }
}
