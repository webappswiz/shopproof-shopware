<?php declare(strict_types=1);

namespace Shopproof\Compliance\Installer;

/**
 * Admin labels the plugin creates in Shopware (custom field sets, fields, options, help texts,
 * Import/Export profiles) in the admin languages beyond English and German (decision 0044).
 * The English and German stay next to the code; the other languages come from
 * Resources/config/label-texts.json, keyed by the English text. A text without a translation
 * shows in English, as Shopware does for any missing locale.
 */
final class LabelTexts
{
    /** Admin locales with their own file entries, besides en-GB and de-DE. */
    public const LOCALES = ['fr-FR', 'it-IT', 'nl-NL', 'es-ES', 'pl-PL'];

    /** @var array<string, array<string, string>>|null */
    private static ?array $texts = null;

    /**
     * A Shopware label in every admin language: locale code => text.
     *
     * @param string $prefix put before every language's text, such as a pictogram code
     *
     * @return array<string, string>
     */
    public static function of(string $english, string $german, string $prefix = ''): array
    {
        $out = ['en-GB' => $prefix . $english, 'de-DE' => $prefix . $german];
        foreach (self::texts()[$english] ?? [] as $locale => $text) {
            $out[$locale] = $prefix . $text;
        }

        return $out;
    }

    /**
     * English text => [locale => text], from the shipped file.
     *
     * @return array<string, array<string, string>>
     */
    public static function texts(): array
    {
        if (self::$texts === null) {
            $raw = @file_get_contents(__DIR__ . '/../Resources/config/label-texts.json');
            $data = \is_string($raw) ? json_decode($raw, true) : null;
            $texts = [];
            if (\is_array($data)) {
                foreach ($data as $english => $byLocale) {
                    if (!\is_string($english) || !\is_array($byLocale)) {
                        continue;
                    }
                    foreach ($byLocale as $locale => $text) {
                        if (\in_array($locale, self::LOCALES, true) && \is_string($text) && $text !== '') {
                            $texts[$english][$locale] = $text;
                        }
                    }
                }
            }
            self::$texts = $texts;
        }

        return self::$texts;
    }
}
