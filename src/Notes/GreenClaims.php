<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * Green claims check (Directive (EU) 2024/825, UCPD Annex I points 4a and 4c): finds the
 * Directive's own example words for generic environmental claims ("eco-friendly", "klimaneutral",
 * ...) in product names and descriptions, in all 24 official EU languages, and lists them as
 * worth checking. Never a verdict: a claim can be fine with a recognised label or with its
 * details on the same page, which only a person can judge.
 *
 * A match is set aside (counted, not listed) when one of the three words before it says the
 * opposite, when the sentence is about disposal, or, for generic and broad claims, when the same
 * sentence gives a percentage or names a recognised ecolabel.
 *
 * Pure: the caller passes plain text. The word lists (green-claims.json) are built from
 * packages/rules (a reviewed rules update), and the WooCommerce plugin and the Shopify app run
 * the same test cases (packages/widgets/test/green-claims-cases.json).
 */
final class GreenClaims
{
    public const KINDS = ['generic', 'broad', 'offsetting'];

    /** Characters that end a word's stem and may change with gender or number. */
    private const VOWELS = 'aeiouyàáâãäåæèéêëìíîïòóôõöøùúûüýőűœăąęėįųūαεηιουωάέήίόύώаеиоуыэюяъьі';

    /** Longest text read per field, in characters. */
    public const MAX_TEXT = 20000;

    /** Characters before and after a match in the excerpt. */
    private const AROUND = 50;

    /** @var array{max_products: int, percent: string, labels: list<string>, languages: array<string, array{terms: list<array{text: string, kind: string}>, not: list<string>, disposal: list<string>}>} */
    private array $data;

    /** @var list<array{lang: string, langs: list<string>, text: string, kind: string, re: string, start: string, length: int, index: int, not: list<string>, disposal: list<string>}> */
    private array $terms = [];

    /** @var array<string, list<int>> */
    private array $byStart = [];

    public function __construct(string $file)
    {
        $raw = is_file($file) ? file_get_contents($file) : false;
        $decoded = \is_string($raw) ? json_decode($raw, true) : null;
        $this->data = self::typed(\is_array($decoded) ? $decoded : []);
        $this->compile();
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array{max_products: int, percent: string, labels: list<string>, languages: array<string, array{terms: list<array{text: string, kind: string}>, not: list<string>, disposal: list<string>}>}
     */
    public static function typed(array $raw): array
    {
        $languages = [];
        foreach (\is_array($raw['languages'] ?? null) ? $raw['languages'] : [] as $lang => $l) {
            if (!\is_string($lang) || !\is_array($l)) {
                continue;
            }
            $terms = [];
            foreach (\is_array($l['terms'] ?? null) ? $l['terms'] : [] as $t) {
                if (\is_array($t) && \is_string($t['text'] ?? null) && trim($t['text']) !== '' && \in_array($t['kind'] ?? null, self::KINDS, true)) {
                    $terms[] = ['text' => $t['text'], 'kind' => (string) $t['kind']];
                }
            }
            $languages[$lang] = [
                'terms' => $terms,
                'not' => self::strings($l['not'] ?? null),
                'disposal' => self::strings($l['disposal'] ?? null),
            ];
        }
        $specific = \is_array($raw['specific'] ?? null) ? $raw['specific'] : [];
        $max = $raw['max_products'] ?? 250;

        return [
            'max_products' => is_numeric($max) ? max(1, (int) $max) : 250,
            'percent' => \is_string($specific['percent'] ?? null) ? $specific['percent'] : '\d\s?%',
            'labels' => self::strings($specific['labels'] ?? null),
            'languages' => $languages,
        ];
    }

    /** Whether the word lists could be read; without them the check says it did not run. */
    public function ready(): bool
    {
        return $this->terms !== [];
    }

    public function maxProducts(): int
    {
        return $this->data['max_products'];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $list): array
    {
        return array_values(array_filter(\is_array($list) ? $list : [], static fn (mixed $v): bool => \is_string($v) && $v !== ''));
    }

    /**
     * @return list<string>
     */
    private static function chars(string $text): array
    {
        $chars = preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY);

        return \is_array($chars) ? $chars : [];
    }

    /**
     * The text as the check reads it: NFC, typographic apostrophes and dashes as plain ones,
     * "CO₂" as "CO2", every kind of space as a plain space. One character for one.
     */
    public static function prepare(string $text): string
    {
        if ($text !== '' && class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($text, \Normalizer::FORM_C);
            $text = \is_string($n) ? $n : $text;
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT, 'UTF-8');
        }
        $text = (string) preg_replace('/[\x{2018}\x{2019}\x{02bc}`\x{00b4}]/u', "'", $text);
        $text = (string) preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $text);
        $text = str_replace("\u{2082}", '2', $text);

        return (string) preg_replace('/[\t\r\f\v\x{00a0}\x{2000}-\x{200a}\x{202f}\x{205f}\x{3000}\x{feff}]/u', ' ', $text);
    }

    private static function wordPattern(string $word): string
    {
        if ($word === 'co2') {
            return 'co\s?2';
        }
        $letters = self::chars($word);
        if (\count($letters) < 5) {
            return preg_quote($word, '/');
        }
        $last = $letters[\count($letters) - 1];
        $stem = str_contains(self::VOWELS, $last) ? implode('', \array_slice($letters, 0, -1)) : $word;

        return preg_quote($stem, '/') . '\p{L}{0,4}';
    }

    /** The pattern for a term, without delimiters. */
    public static function termPattern(string $text): string
    {
        $words = preg_split('/[\s-]+/u', mb_strtolower(self::prepare($text), 'UTF-8'), -1, \PREG_SPLIT_NO_EMPTY);
        $parts = array_map(self::wordPattern(...), \is_array($words) ? $words : []);

        return '(?<![\p{L}\p{N}])' . implode('[\s-]?', $parts) . '(?![\p{L}\p{N}])';
    }

    /** The first one to three letters of a term, lower case, for the quick pre-check. */
    public static function termStart(string $text): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(self::prepare($text), 'UTF-8'), -1, \PREG_SPLIT_NO_EMPTY);
        $first = \is_array($words) && isset($words[0]) ? $words[0] : '';
        if (str_starts_with($first, 'co2')) {
            return 'co';
        }

        return implode('', \array_slice(self::chars($first), 0, 3));
    }

    /**
     * @return array<string, true>
     */
    private static function wordStarts(string $text): array
    {
        $out = [];
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, \PREG_SPLIT_NO_EMPTY);
        foreach (\is_array($words) ? $words : [] as $w) {
            $chars = self::chars($w);
            $max = min(3, \count($chars));
            for ($n = 1; $n <= $max; ++$n) {
                $out[implode('', \array_slice($chars, 0, $n))] = true;
            }
        }

        return $out;
    }

    /**
     * One entry per pattern, longest first. The same word in more than one language is checked
     * once, with the opposite words and disposal stems of all of them.
     */
    private function compile(): void
    {
        $byPattern = [];
        $index = 0;
        foreach ($this->data['languages'] as $lang => $l) {
            foreach ($l['terms'] as $t) {
                $source = self::termPattern($t['text']);
                if (isset($byPattern[$source])) {
                    $byPattern[$source]['langs'][] = $lang;
                    $byPattern[$source]['not'] = [...$byPattern[$source]['not'], ...$l['not']];
                    $byPattern[$source]['disposal'] = [...$byPattern[$source]['disposal'], ...$l['disposal']];
                    continue;
                }
                $byPattern[$source] = [
                    'lang' => $lang,
                    'langs' => [$lang],
                    'text' => $t['text'],
                    'kind' => $t['kind'],
                    're' => '/' . $source . '/iu',
                    'start' => self::termStart($t['text']),
                    'length' => \count(self::chars($t['text'])),
                    'index' => $index++,
                    'not' => $l['not'],
                    'disposal' => $l['disposal'],
                ];
            }
        }
        $terms = array_values($byPattern);
        usort($terms, static fn (array $a, array $b): int => [$b['length'], $a['lang'], $a['index']] <=> [$a['length'], $b['lang'], $b['index']]);
        $this->terms = $terms;
        $this->byStart = [];
        foreach ($terms as $i => $t) {
            $this->byStart[$t['start']][] = $i;
        }
    }

    /**
     * @return list<string>
     */
    public static function sentences(string $text): array
    {
        $out = [];
        $parts = preg_split('/\n+|(?<=[.!?;])\s+/u', $text);
        foreach (\is_array($parts) ? $parts : [] as $s) {
            $s = trim((string) preg_replace('/ {2,}/', ' ', $s));
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function wordsBefore(string $sentence, int $offset): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(substr($sentence, 0, $offset), 'UTF-8'), -1, \PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach (\is_array($words) ? $words : [] as $w) {
            $w = (string) preg_replace('/^[^\p{L}\p{N}\']+|[^\p{L}\p{N}\']+$/u', '', $w);
            if ($w !== '') {
                $out[] = $w;
            }
        }

        return \array_slice($out, -3);
    }

    /** A short excerpt around a match (byte offsets), cut at word boundaries, with "...". */
    public static function excerpt(string $sentence, int $start, int $end): string
    {
        $chars = self::chars($sentence);
        $len = \count($chars);
        $from = \count(self::chars(substr($sentence, 0, $start)));
        $to = $from + \count(self::chars(substr($sentence, $start, $end - $start)));
        $a = max(0, $from - self::AROUND);
        $b = min($len, $to + self::AROUND);
        if ($a > 0) {
            $space = -1;
            for ($i = $a; $i < $len; ++$i) {
                if ($chars[$i] === ' ') {
                    $space = $i;
                    break;
                }
            }
            $a = $space !== -1 && $space < $from ? $space + 1 : $from;
        }
        if ($b < $len) {
            $space = -1;
            for ($i = min($b, $len - 1); $i >= 0; --$i) {
                if ($chars[$i] === ' ') {
                    $space = $i;
                    break;
                }
            }
            $b = $space >= $to ? $space : $to;
        }

        return ($a > 0 ? '...' : '') . trim(implode('', \array_slice($chars, $a, $b - $a))) . ($b < $len ? '...' : '');
    }

    /**
     * Green claims in one text.
     *
     * @return array{found: list<array{term: string, kind: string, langs: list<string>, excerpt: string}>, aside: array{not: int, disposal: int, specific: int}}
     */
    public function scanText(string $text): array
    {
        $found = [];
        $aside = ['not' => 0, 'disposal' => 0, 'specific' => 0];
        $seen = [];
        foreach (self::sentences(self::prepare($text)) as $sentence) {
            $candidates = [];
            foreach (array_keys(self::wordStarts($sentence)) as $w) {
                foreach ($this->byStart[$w] ?? [] as $i) {
                    $candidates[$i] = true;
                }
            }
            if ($candidates === []) {
                continue;
            }
            ksort($candidates);
            $lower = mb_strtolower($sentence, 'UTF-8');
            $specific = preg_match('/' . str_replace('/', '\/', $this->data['percent']) . '/iu', $sentence) === 1;
            foreach ($this->data['labels'] as $label) {
                $specific = $specific || str_contains($lower, $label);
            }
            $taken = [];
            foreach (array_keys($candidates) as $i) {
                $t = $this->terms[$i];
                if (!preg_match_all($t['re'], $sentence, $matches, \PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($matches[0] as [$match, $offset]) {
                    $start = (int) $offset;
                    $end = $start + \strlen($match);
                    foreach ($taken as $x) {
                        if ($start < $x['end'] && $end > $x['start']) {
                            continue 2;
                        }
                    }
                    $taken[] = ['start' => $start, 'end' => $end, 'i' => $i];
                }
            }
            usort($taken, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            foreach ($taken as $x) {
                $t = $this->terms[$x['i']];
                if (array_intersect(self::wordsBefore($sentence, $x['start']), $t['not']) !== []) {
                    ++$aside['not'];
                } elseif (self::containsAny($lower, $t['disposal'])) {
                    ++$aside['disposal'];
                } elseif ($specific && $t['kind'] !== 'offsetting') {
                    ++$aside['specific'];
                } elseif (!isset($seen[$t['text']])) {
                    $seen[$t['text']] = true;
                    $found[] = [
                        'term' => $t['text'],
                        'kind' => $t['kind'],
                        'langs' => $t['langs'],
                        'excerpt' => self::excerpt($sentence, $x['start'], $x['end']),
                    ];
                }
            }
        }

        return ['found' => $found, 'aside' => $aside];
    }

    /**
     * @param list<string> $stems
     */
    private static function containsAny(string $lower, array $stems): bool
    {
        foreach ($stems as $stem) {
            if (str_contains($lower, mb_strtolower($stem, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Green claims in a list of products (title and plain-text description).
     *
     * @param list<array{id: string, title: string, text: string}> $products
     *
     * @return array{checked: int, products: list<array{id: string, title: string, found: list<array{term: string, kind: string, langs: list<string>, field: string, excerpt: string}>}>, aside: array{not: int, disposal: int, specific: int}}
     */
    public function scanProducts(array $products): array
    {
        $out = [];
        $aside = ['not' => 0, 'disposal' => 0, 'specific' => 0];
        $checked = 0;
        foreach ($products as $p) {
            if ($checked >= $this->data['max_products']) {
                break;
            }
            ++$checked;
            $found = [];
            $terms = [];
            foreach (['title' => $p['title'], 'description' => $p['text']] as $field => $text) {
                $r = $this->scanText($text);
                $aside['not'] += $r['aside']['not'];
                $aside['disposal'] += $r['aside']['disposal'];
                $aside['specific'] += $r['aside']['specific'];
                foreach ($r['found'] as $f) {
                    if (isset($terms[$f['term']])) {
                        continue;
                    }
                    $terms[$f['term']] = true;
                    $found[] = [...$f, 'field' => $field];
                }
            }
            if ($found !== []) {
                $out[] = ['id' => $p['id'], 'title' => $p['title'], 'found' => $found];
            }
        }

        return ['checked' => $checked, 'products' => $out, 'aside' => $aside];
    }

    /** Plain text of product HTML: line breaks for blocks, no tags or entities. */
    public static function plain(string $html): string
    {
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $html = (string) preg_replace('/<br\s*\/?>|<\/(p|li|div|h[1-6]|tr|td|th|dd|dt)>/i', "\n", $html);

        return html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
