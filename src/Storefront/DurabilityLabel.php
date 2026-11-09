<?php declare(strict_types=1);

namespace Shopproof\Compliance\Storefront;

/**
 * The EU harmonised durability label (Implementing Regulation (EU) 2025/1960, Annex II; rule
 * EU-GU-02) for products whose producer offers a commercial guarantee of durability of more than
 * two years. The official artwork is drawn unchanged; only its placeholder words are covered and
 * replaced with the years, brand and model, in Inter, as Annex II allows. Same layout data and
 * method as the WooCommerce plugin. No Shopware dependencies, so it is unit tested alone.
 */
final class DurabilityLabel
{
    public const PREFIX = 'shopproof_durability_';

    public const FIELDS = ['years', 'brand', 'model', 'display'];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Label values for a product: its own fields, the brand falling back to the Shopware
     * manufacturer's name and the model to the manufacturer's product number.
     *
     * @param array<mixed>|null $fields product custom fields
     *
     * @return array{years: int, brand: string, model: string, display: string}
     */
    public static function values(?array $fields, ?string $manufacturerName, ?string $manufacturerNumber): array
    {
        $get = static function (string $key) use ($fields): string {
            $value = \is_array($fields) ? ($fields[self::PREFIX . $key] ?? null) : null;

            return \is_string($value) || \is_int($value) ? trim((string) $value) : '';
        };
        $years = $get('years');
        $brand = $get('brand');
        $model = $get('model');

        return [
            'years' => preg_match('/^\d{1,2}$/', $years) === 1 ? (int) $years : 0,
            'brand' => $brand !== '' ? $brand : trim((string) $manufacturerName),
            'model' => $model !== '' ? $model : trim((string) $manufacturerNumber),
            // The small strip is the default: it opens the full label and keeps Add to cart in view.
            'display' => $get('display') === 'full' ? 'full' : 'nested',
        ];
    }

    /**
     * @param array{years: int, brand: string, model: string, display: string} $values
     */
    public function valid(array $values): bool
    {
        $min = $this->number($this->data(), 'min_years');
        $max = $this->number($this->data(), 'max_years');

        return $min > 0 && $values['years'] >= $min && $values['years'] <= $max && $values['brand'] !== '' && $values['model'] !== '';
    }

    /**
     * The language for the text alternative and link: the page language when available, else
     * the shop country's language, else English.
     */
    public function language(string $pageLanguage, string $countryLanguage = ''): string
    {
        $texts = $this->group($this->data(), 'text');
        foreach ([strtolower(substr($pageLanguage, 0, 2)), strtolower($countryLanguage)] as $lang) {
            if ($lang !== '' && isset($texts[$lang])) {
                return $lang;
            }
        }

        return 'en';
    }

    /**
     * Estimated width of a value in em, from the kinds of its characters.
     */
    public function widthEm(string $value): float
    {
        $fit = $this->group($this->data(), 'fit');
        $em = 0.0;
        foreach (preg_split('//u', $value, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            if (preg_match('/^[0-9]$/', $c) === 1) {
                $kind = 'digit';
            } elseif ($c === ' ') {
                $kind = 'space';
            } elseif (mb_strtolower($c) !== $c) {
                $kind = 'upper';
            } elseif (mb_strtoupper($c) !== $c) {
                $kind = 'lower';
            } else {
                $kind = 'other';
            }
            $em += $this->number($fit, $kind);
        }

        return $em;
    }

    /**
     * HTML for the label, or '' when the values do not make a valid label.
     *
     * @param array{years: int, brand: string, model: string, display: string} $values
     * @param string                                                         $assetBase URL of the durability asset folder, ending in /
     */
    public function render(array $values, string $pageLanguage, string $countryLanguage, string $assetBase): string
    {
        if (!$this->valid($values)) {
            return '';
        }
        $data = $this->data();
        $lang = $this->language($pageLanguage, $countryLanguage);
        $text = $this->group($this->group($data, 'text'), $lang);
        $vars = ['{years}' => (string) $values['years'], '{brand}' => $values['brand'], '{model}' => $values['model']];
        $langAttr = strtolower(substr($pageLanguage, 0, 2)) !== $lang ? ' lang="' . self::e($lang) . '"' : '';
        $full = $this->svg('label', $values, strtr($this->text($text, 'alt'), $vars), $assetBase);
        $link = '<p class="shopproof-durability-link"><a href="' . self::e($this->text($data, 'portal')) . '">' . self::e($this->text($text, 'link')) . '</a></p>';
        $styles = $this->styles($assetBase);
        if ($values['display'] === 'nested') {
            $strip = $this->svg('nested', $values, strtr($this->text($text, 'alt_nested'), $vars), $assetBase);

            return $styles . '<details class="shopproof shopproof-durability"' . $langAttr . '><summary>' . $strip . '</summary>' . $full . $link . '</details>';
        }

        return $styles . '<figure class="shopproof shopproof-durability"' . $langAttr . '>' . $full . $link . '</figure>';
    }

    /**
     * @param array{years: int, brand: string, model: string, display: string} $values
     */
    private function svg(string $part, array $values, string $alt, string $assetBase): string
    {
        $layout = $this->group($this->data(), $part);
        $width = $this->number($layout, 'width');
        $height = $this->number($layout, 'height');
        $html = \sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s" width="%1$s" height="%2$s" role="img" aria-label="%3$s" class="shopproof-durability-svg"><image href="%4$s" width="%1$s" height="%2$s"/>',
            self::e((string) $width),
            self::e((string) $height),
            self::e($alt),
            self::e($assetBase . $this->text($layout, 'file'))
        );
        foreach ($this->group($layout, 'blank') as $blank) {
            $blank = \is_array($blank) ? $blank : [];
            $html .= \sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" fill="#fff"/>',
                self::e((string) $this->number($blank, 'x')),
                self::e((string) $this->number($blank, 'y')),
                self::e((string) $this->number($blank, 'width')),
                self::e((string) $this->number($blank, 'height'))
            );
        }
        $fields = $this->group($layout, 'fields');
        foreach (['years' => (string) $values['years'], 'brand' => $values['brand'], 'model' => $values['model']] as $name => $value) {
            if (isset($fields[$name]) && \is_array($fields[$name])) {
                $html .= $this->field($fields[$name], $value);
            }
        }

        return $html . '</svg>';
    }

    /**
     * One editable field as SVG text, set smaller and held to its width when it would not fit.
     *
     * @param array<mixed> $field
     */
    private function field(array $field, string $value): string
    {
        $size = $this->number($field, 'size');
        $max = $this->number($field, 'max_width');
        $estimate = $this->widthEm($value) * $size;
        $extra = '';
        if ($estimate > $max) {
            $size = round($size * $max / $estimate, 2);
            $extra = \sprintf(' textLength="%s" lengthAdjust="spacingAndGlyphs"', self::e((string) $max));
        }

        return \sprintf(
            '<text x="%s" y="%s" font-size="%s" font-weight="%s" text-anchor="%s" fill="#000"%s>%s</text>',
            self::e((string) $this->number($field, 'x')),
            self::e((string) $this->number($field, 'y')),
            self::e((string) $size),
            self::e((string) $this->number($field, 'weight')),
            self::e($this->text($field, 'anchor')),
            $extra,
            self::e($value)
        );
    }

    private function styles(string $assetBase): string
    {
        $data = $this->data();
        $family = $this->text($data, 'font_family');
        $css = '';
        foreach ($this->group($data, 'fonts') as $font) {
            $font = \is_array($font) ? $font : [];
            $css .= \sprintf(
                "@font-face{font-family:'%s';font-style:normal;font-weight:%s;font-display:swap;src:url(%s) format('woff2');unicode-range:%s}",
                self::css($family),
                self::css((string) $this->number($font, 'weight')),
                self::css($assetBase . $this->text($font, 'file')),
                self::css($this->text($font, 'range'))
            );
        }
        $css .= \sprintf(".shopproof-durability-svg{display:block;inline-size:100%%;block-size:auto;max-inline-size:360px;font-family:'%s',sans-serif}", self::css($family));
        $css .= 'figure.shopproof-durability{margin:0 0 1rem}details.shopproof-durability{margin:0 0 1rem}.shopproof-durability>summary{list-style:none;cursor:pointer;max-inline-size:260px}.shopproof-durability>summary::-webkit-details-marker{display:none}.shopproof-durability-link{margin-block:0.5em 0}';

        return '<style>' . $css . '</style>';
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        if ($this->data === null) {
            $raw = is_file($this->path) ? file_get_contents($this->path) : false;
            $decoded = \is_string($raw) ? json_decode($raw, true) : null;
            $data = [];
            if (\is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $data[(string) $key] = $value;
                }
            }
            $this->data = $data;
        }

        return $this->data;
    }

    /**
     * @param array<mixed> $from
     *
     * @return array<mixed>
     */
    private function group(array $from, string $key): array
    {
        return \is_array($from[$key] ?? null) ? $from[$key] : [];
    }

    /**
     * @param array<mixed> $from
     */
    private function number(array $from, string $key): float|int
    {
        $value = $from[$key] ?? 0;

        return \is_int($value) || \is_float($value) ? $value : 0;
    }

    /**
     * @param array<mixed> $from
     */
    private function text(array $from, string $key): string
    {
        $value = $from[$key] ?? '';

        return \is_string($value) ? $value : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    /** Characters that could end a CSS string or url() are dropped; the values come from our own build. */
    private static function css(string $value): string
    {
        return str_replace(["'", '"', '\\', '<', '>', ';', '}', '{', ')', '('], '', $value);
    }
}
