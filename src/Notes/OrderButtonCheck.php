<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * The order button part of the setup check: the button that places the order must say that the
 * order costs money (Directive 2011/83/EU, Article 8(2), and the national rules such as § 312j(3)
 * BGB). Shopware's German text "Zahlungspflichtig bestellen" is the wording the law names; its
 * English text "Submit order" is not. Compares the button text of each storefront language
 * (snippet checkout.confirmSubmit) with the words the law names in that language. Pure, unit
 * tested alone.
 *
 * Other words can be as clear, so a different text is "check", never an error. A language without
 * such words is "not checked", never done.
 */
final class OrderButtonCheck
{
    /** Shopware's snippet for the text of the order button on the confirm page. */
    public const SNIPPET = 'checkout.confirmSubmit';

    /**
     * @param list<array{channel: string, locale: string, text: string}> $buttons the button text per
     *                                                                           storefront language
     * @param array<string, string>                                     $words   the law's words by
     *                                                                           language code
     *
     * @return list<array{key: string, ok: bool, detail?: string, channel?: string}>
     */
    public static function evaluate(array $buttons, array $words): array
    {
        if ($buttons === []) {
            return [];
        }
        $notes = [];
        // Storefront names only when there is more than one, as for the withdrawal policy lines.
        $named = \count(array_unique(array_column($buttons, 'channel'))) > 1;
        foreach ($buttons as $button) {
            $lang = strtolower(substr($button['locale'], 0, 2));
            $law = $words[$lang] ?? '';
            $line = ['channel' => $named ? $button['channel'] : ''];
            if ($law === '') {
                $notes[] = ['key' => 'orderButtonUnknown', 'ok' => false, 'detail' => $button['locale'] . ': "' . $button['text'] . '"'] + self::named($line);
            } elseif (!self::matches($button['text'], $law)) {
                $notes[] = ['key' => 'orderButton', 'ok' => false, 'detail' => $button['locale'] . ': "' . $button['text'] . '" / "' . $law . '"'] + self::named($line);
            }
        }

        return $notes !== [] ? $notes : [['key' => 'orderButton', 'ok' => true]];
    }

    /**
     * Whether a button text is the law's words (case, spaces and markup ignored).
     */
    public static function matches(string $text, string $law): bool
    {
        $norm = static fn (string $s): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags($s), \ENT_QUOTES | \ENT_HTML5))));

        return $law !== '' && $norm($text) === $norm($law);
    }

    /**
     * The words the law names, by language, from the file the build copies from the WooCommerce
     * plugin (built from the reviewed labels in packages/rules).
     *
     * @return array<string, string>
     */
    public static function words(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $languages = \is_array($data) && \is_array($data['languages'] ?? null) ? $data['languages'] : [];
        $out = [];
        foreach ($languages as $lang => $entry) {
            if (\is_string($lang) && \is_array($entry) && \is_string($entry['label'] ?? null) && $entry['label'] !== '') {
                $out[$lang] = $entry['label'];
            }
        }

        return $out;
    }

    /**
     * @param array{channel: string} $line
     *
     * @return array{channel?: string}
     */
    private static function named(array $line): array
    {
        return $line['channel'] !== '' ? ['channel' => $line['channel']] : [];
    }
}
