<?php declare(strict_types=1);

namespace Shopproof\Compliance\Storefront;

/**
 * EU energy label arrow, product information sheet link and the French repairability or
 * durability index next to the price (gap audit 22 Nov 2026, item 5; rules EU-EL-01, FR-EN-01).
 *
 * Energy label, distance selling (Regulation (EU) 2017/1369 Art. 5 and the product delegated acts,
 * for example Delegated Regulation (EU) 2019/2014 Annex VIII): an arrow in the colour of the class
 * with the class letter in white and a black border and the range of classes in black, near the
 * price, that opens the label on one click, and a link that says "Product information sheet",
 * wherever the price shows (list pages included). French indices (Code de l'environnement
 * L. 541-9-2): the producer's pictogram near the price, the detail one click away.
 *
 * The same steps, texts and HTML as on Shopify and WooCommerce (packages/widgets/src/
 * energy-label.mjs, built into config/energy-label.json), tested on the same cases. No Shopware
 * dependencies, so it is unit tested alone.
 */
final class EnergyLabel
{
    public const PREFIX = 'shopproof_energy_';

    /** Custom field (after PREFIX) for each key of the shared steps. */
    public const FIELDS = [
        'class' => 'class',
        'range' => 'range',
        'eprel' => 'eprel',
        'label' => 'label',
        'sheet' => 'sheet',
        'fr_type' => 'frType',
        'fr_score' => 'frScore',
        'fr_logo' => 'frLogo',
        'fr_detail' => 'frDetail',
    ];

    /** The classes and ranges for the admin selects; a unit test keeps them equal to energy-label.json. */
    public const CLASSES = ['A+++', 'A++', 'A+', 'A', 'B', 'C', 'D', 'E', 'F', 'G'];

    public const RANGES = ['A-G', 'A+++-D', 'A++-E', 'A+-F', 'A+-G', 'A++-G'];

    /** Fields that hold a Shopware media id. */
    public const MEDIA = ['label', 'fr_logo'];

    /** @var array<mixed>|null */
    private ?array $data = null;

    public function __construct(private readonly string $path)
    {
    }

    /** @return array<mixed> */
    public function data(): array
    {
        if ($this->data === null) {
            $raw = is_file($this->path) ? file_get_contents($this->path) : false;
            $data = \is_string($raw) ? json_decode($raw, true) : null;
            $this->data = \is_array($data) ? $data : [];
        }

        return $this->data;
    }

    /** @return array<string> */
    private function group(string $key): array
    {
        $data = $this->data();
        $list = isset($data[$key]) && \is_array($data[$key]) ? $data[$key] : [];

        return array_filter($list, '\is_string');
    }

    public static function cleanUrl(mixed $value): string
    {
        $v = trim(\is_scalar($value) ? (string) $value : '');

        return \strlen($v) <= 2000 && preg_match('#^https://[^\s"\'<>]+$#i', $v) === 1 ? $v : '';
    }

    public static function cleanEprel(mixed $value): string
    {
        $v = trim(\is_scalar($value) ? (string) $value : '');

        return preg_match('/^[0-9]{1,12}$/', $v) === 1 ? $v : '';
    }

    public function cleanClass(mixed $value): string
    {
        $v = \is_scalar($value) ? (string) $value : '';
        $v = strtoupper((string) preg_replace('/\s+/u', '', str_replace("\u{207A}", '+', $v)));

        return \in_array($v, $this->group('classes'), true) ? $v : '';
    }

    public function cleanRange(mixed $value): string
    {
        $v = strtoupper((string) preg_replace('/\s+/u', '', \is_scalar($value) ? (string) $value : ''));
        $v = str_replace(['TO', "\u{2013}", "\u{2014}"], '-', $v);

        return \in_array($v, $this->group('ranges'), true) ? $v : '';
    }

    /** @return array{0: string, 1: string} */
    public static function rangeEnds(string $range): array
    {
        $cut = (int) strrpos($range, '-');

        return [substr($range, 0, $cut), substr($range, $cut + 1)];
    }

    /** @return list<string> */
    public function rangeClasses(string $range): array
    {
        [$top, $bottom] = self::rangeEnds($range);
        $all = $this->group('classes');
        $from = array_search($top, $all, true);
        $to = array_search($bottom, $all, true);
        if ($from === false || $to === false) {
            return [];
        }

        return array_values(\array_slice($all, (int) $from, (int) $to - (int) $from + 1));
    }

    public function colourOf(string $cls, string $range): string
    {
        $list = $this->rangeClasses($range);
        $colours = $this->group('colours');
        $i = array_search($cls, $list, true);
        if ($i === false || $colours === []) {
            return '';
        }
        $n = \count($list);

        return $colours[$n === 1 ? 0 : (int) round(($i * (\count($colours) - 1)) / ($n - 1))] ?? '';
    }

    public static function cleanScore(mixed $value): ?float
    {
        $v = trim(\is_scalar($value) ? (string) $value : '');
        $v = (string) preg_replace('/,/', '.', $v, 1);
        if (preg_match('/^[0-9]{1,2}(\.[0-9])?$/', $v) !== 1) {
            return null;
        }
        $n = (float) $v;

        return $n >= 0 && $n <= 10 ? $n : null;
    }

    public static function scoreText(float $score): string
    {
        $text = floor($score) === $score ? (string) (int) $score : (string) $score;

        return str_replace('.', ',', $text) . '/10';
    }

    /** @param array<string, mixed> $raw */
    private static function has(array $raw, string $key): bool
    {
        return isset($raw[$key]) && \is_scalar($raw[$key]) && trim((string) $raw[$key]) !== '';
    }

    /**
     * The fields checked, as the shared steps do. Missing parts are listed in problems.
     *
     * @param array<string, mixed> $raw class, range, eprel, label, sheet, frType, frScore, frLogo, frDetail
     *
     * @return array{energy: array<string, string>|null, fr: array{type: string, score: float, logo: string, detail: string}|null, problems: list<string>}
     */
    public function values(array $raw): array
    {
        $get = static fn (string $key): mixed => $raw[$key] ?? '';
        $cls = $this->cleanClass($get('class'));
        $range = $this->cleanRange($get('range'));
        if ($range === '') {
            $range = $cls !== '' && \in_array($cls, $this->rangeClasses('A-G'), true) ? 'A-G' : '';
        }
        $eprel = self::cleanEprel($get('eprel'));
        $label = self::cleanUrl($get('label'));
        $sheet = self::cleanUrl($get('sheet'));
        $frType = \in_array($get('frType'), ['repair', 'durability'], true) ? (string) $get('frType') : '';
        $frScore = self::cleanScore($get('frScore'));
        $frLogo = self::cleanUrl($get('frLogo'));
        $frDetail = self::cleanUrl($get('frDetail'));
        $base = \is_string($this->data()['eprel'] ?? null) ? $this->data()['eprel'] : '';
        $problems = [];
        $energy = null;
        if (self::has($raw, 'class')) {
            if ($cls === '') {
                $problems[] = 'class';
            } elseif ($range === '' || !\in_array($cls, $this->rangeClasses($range), true)) {
                $problems[] = 'range';
            } elseif ($label === '' && $eprel === '') {
                $problems[] = 'label';
            } else {
                [$top, $bottom] = self::rangeEnds($range);
                $energy = [
                    'class' => $cls,
                    'range' => $range,
                    'top' => $top,
                    'bottom' => $bottom,
                    'colour' => $this->colourOf($cls, $range),
                    'labelUrl' => $label !== '' ? $label : $base . $eprel,
                    'sheetUrl' => $eprel !== '' ? $base . $eprel : $sheet,
                ];
                if ($energy['sheetUrl'] === '') {
                    $problems[] = 'sheet';
                }
            }
        }
        $fr = null;
        if ($frType !== '' || self::has($raw, 'frScore') || $frLogo !== '') {
            if ($frType === '') {
                $problems[] = 'frType';
            } elseif ($frScore === null) {
                $problems[] = 'frScore';
            } elseif ($frLogo === '') {
                $problems[] = 'frLogo';
            } else {
                $fr = ['type' => $frType, 'score' => $frScore, 'logo' => $frLogo, 'detail' => $frDetail];
                if ($frDetail === '') {
                    $problems[] = 'frDetail';
                }
            }
        }

        return ['energy' => $energy, 'fr' => $fr, 'problems' => $problems];
    }

    /**
     * The values of a variant, falling back group by group to its parent's values.
     *
     * @param array<string, mixed> $own
     * @param array<string, mixed> $parent
     *
     * @return array<string, string>
     */
    public static function withParent(array $own, array $parent): array
    {
        $groups = [['class', 'range', 'eprel', 'label', 'sheet'], ['frType', 'frScore', 'frLogo', 'frDetail']];
        $out = [];
        foreach ($groups as $keys) {
            $from = $parent;
            foreach ($keys as $k) {
                if (self::has($own, $k)) {
                    $from = $own;
                    break;
                }
            }
            foreach ($keys as $k) {
                $out[$k] = isset($from[$k]) && \is_scalar($from[$k]) ? (string) $from[$k] : '';
            }
        }

        return $out;
    }

    /** @return array{arrow: string, sheet: string} */
    public function texts(string $lang): array
    {
        $arrow = $this->group('arrow');
        $sheet = $this->group('sheet');
        $l = strtolower(substr($lang, 0, 2));
        $l = isset($arrow[$l]) ? $l : 'en';

        return ['arrow' => $arrow[$l] ?? '', 'sheet' => $sheet[$l] ?? ''];
    }

    /** HTML escaping exactly like the shared JavaScript, so every platform prints the same. */
    public static function esc(string $s): string
    {
        return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }

    private static function sup(string $cls, int $x, int $y, int $size): string
    {
        $plus = substr($cls, 1);
        $raised = $plus !== '' ? '<tspan font-size="' . (int) round($size * 0.6) . '" dy="-' . (int) round($size * 0.35) . '">' . $plus . '</tspan>' : '';

        return '<text x="' . $x . '" y="' . $y . '" font-size="' . $size . '">' . $cls[0] . $raised . '</text>';
    }

    /** @param array<string, string> $e */
    public static function arrowSvg(array $e): string
    {
        $rangeW = 32 + 16 * (max(\strlen($e['top']), \strlen($e['bottom'])) - 1);
        $body = 62 + 26 * (\strlen($e['class']) - 1) + 24;
        $x0 = $rangeW + 6;
        $tip = 44;
        $w = $x0 + $body + $tip + 4;
        $points = $x0 . ',4 ' . ($x0 + $body) . ',4 ' . ($x0 + $body + $tip) . ',60 ' . ($x0 + $body) . ',116 ' . $x0 . ',116';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' 120" width="' . $w . '" height="120" aria-hidden="true" focusable="false">'
            . '<g fill="#000" font-family="Calibri,Carlito,Arial,sans-serif" font-weight="700">'
            . self::sup($e['top'], 1, 38, 42)
            . '<path d="M10 46v26h-6l10 14 10-14h-6V46z"/>'
            . self::sup($e['bottom'], 1, 118, 42)
            . '</g>'
            . '<polygon points="' . $points . '" fill="' . $e['colour'] . '" stroke="#000" stroke-width="5" stroke-linejoin="round"/>'
            . '<g font-family="Calibri,Carlito,Arial,sans-serif" font-weight="700" fill="#fff" stroke="#000" stroke-width="5" paint-order="stroke" stroke-linejoin="round">'
            . self::sup($e['class'], $x0 + 12, 96, 92)
            . '</g></svg>';
    }

    /**
     * The HTML next to the price: phrasing content only. '' when there is nothing to show.
     *
     * @param array{energy: array<string, string>|null, fr: array{type: string, score: float, logo: string, detail: string}|null, problems?: list<string>} $values
     * @param array{arrow: string, sheet: string} $t
     */
    public function html(array $values, array $t): string
    {
        $html = '';
        $e = $values['energy'];
        if ($e !== null) {
            $name = str_replace(['{class}', '{top}', '{bottom}'], [$e['class'], $e['top'], $e['bottom']], $t['arrow']);
            $html .= '<a class="shopproof-energy-arrow" href="' . self::esc($e['labelUrl']) . '" aria-label="' . self::esc($name) . '">' . self::arrowSvg($e) . '</a>';
            if ($e['sheetUrl'] !== '') {
                $html .= '<a class="shopproof-energy-sheet" href="' . self::esc($e['sheetUrl']) . '">' . self::esc($t['sheet']) . '</a>';
            }
        }
        $f = $values['fr'];
        if ($f !== null) {
            $fr = $this->group('fr');
            $alt = ($fr[$f['type']] ?? '') . ' : ' . self::scoreText($f['score']);
            $img = '<img class="shopproof-fr-index-logo" src="' . self::esc($f['logo']) . '" alt="' . self::esc($f['detail'] !== '' ? $alt . ', ' . ($fr['detail'] ?? '') : $alt) . '" loading="lazy" decoding="async">';
            $html .= $f['detail'] !== ''
                ? '<a class="shopproof-fr-index" href="' . self::esc($f['detail']) . '" lang="fr">' . $img . '</a>'
                : '<span class="shopproof-fr-index" lang="fr">' . $img . '</span>';
        }

        return $html !== '' ? '<span class="shopproof-energy">' . $html . '</span>' : '';
    }

    public function css(): string
    {
        $css = $this->data()['css'] ?? '';

        return \is_string($css) ? $css : '';
    }

    /**
     * The shared keys from a product's custom fields. Media ids become addresses through $media.
     *
     * @param array<mixed>|null              $fields custom fields
     * @param callable(string): string|null $media  media id to absolute address, '' when unknown
     *
     * @return array<string, string>
     */
    public static function fromCustomFields(?array $fields, ?callable $media = null): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $key) {
            $v = \is_array($fields) ? ($fields[self::PREFIX . $field] ?? null) : null;
            $v = \is_scalar($v) ? trim((string) $v) : '';
            if ($v !== '' && \in_array($field, self::MEDIA, true)) {
                $v = $media !== null && preg_match('/^[0-9a-f]{32}$/', $v) === 1 ? $media($v) : '';
            }
            $out[$key] = $v;
        }

        return $out;
    }
}
