<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * The shopper texts of the shop notes (food information, deposit, price notes, checkout boxes,
 * dispute statement). Resources/config/notes-texts.json is copied from the Shopify theme
 * extension's reviewed locale files by apps/wordpress/bin/bundle-notes-texts.mjs, so the three
 * platforms show the same words in en, de, fr, it and nl. CI checks the copy is current.
 */
final class NotesTexts
{
    public const LANGUAGES = ['en', 'de', 'fr', 'it', 'nl'];

    /** Language of each launch country, for text when the page language has none. */
    public const COUNTRY_LANGUAGE = ['AT' => 'de', 'DE' => 'de', 'FR' => 'fr', 'IT' => 'it', 'NL' => 'nl'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $data = null;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * The language to show: the page language when there are texts for it, else the shop
     * country's language, else English.
     */
    public static function language(string $locale, string $shopCountry = ''): string
    {
        $page = strtolower(substr($locale, 0, 2));
        if (\in_array($page, self::LANGUAGES, true)) {
            return $page;
        }

        return self::COUNTRY_LANGUAGE[strtoupper($shopCountry)] ?? 'en';
    }

    /**
     * A text by its dotted key ("notices.inclVat", "food.a.milk") with {name} placeholders filled
     * in. Falls back to English, then to ''. Values are not escaped: the caller escapes them.
     *
     * @param array<string, string> $vars
     */
    public function text(string $key, string $lang, array $vars = []): string
    {
        $data = $this->data();
        foreach ([$lang, 'en'] as $try) {
            $value = $data[$try] ?? null;
            foreach (explode('.', $key) as $part) {
                $value = \is_array($value) ? ($value[$part] ?? null) : null;
            }
            if (\is_string($value)) {
                $pairs = [];
                foreach ($vars as $name => $var) {
                    $pairs['{' . $name . '}'] = $var;
                }

                return strtr($value, $pairs);
            }
        }

        return '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;
        $decoded = \is_string($raw) ? json_decode($raw, true) : null;
        $this->data = [];
        foreach (\is_array($decoded) ? $decoded : [] as $lang => $texts) {
            if (\is_string($lang) && \is_array($texts)) {
                $this->data[$lang] = array_filter($texts, static fn (mixed $k): bool => \is_string($k), \ARRAY_FILTER_USE_KEY);
            }
        }

        return $this->data;
    }
}
