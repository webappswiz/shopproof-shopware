<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

/**
 * The quiet email (zero-thought plan item 4), as in the WooCommerce plugin: one email to the shop
 * owner, only when a card at the top of the Shopproof page says "Needs action" (a refund due today
 * or late, a withdrawal request that could not be recorded, a link check that found a problem) or
 * a refund is due in the next 3 days. Setup steps, "Needs a look"
 * cards and anything that could not be checked never send an email. At most one a day, and the
 * same list is not sent again within 7 days. Pure, so it is unit tested alone; the words come from
 * the admin snippets, so the email says what the page says.
 */
final class QuietEmail
{
    /** System config keys of the off switch and the last email. */
    public const OFF = 'ShopproofCompliance.quietEmail.off';
    public const SENT_TIME = 'ShopproofCompliance.quietEmail.sentTime';
    public const SENT_DIGEST = 'ShopproofCompliance.quietEmail.sentDigest';

    /** Days before the same list is sent again. */
    public const REPEAT_DAYS = 7;

    /** Admin languages with snippet files. */
    public const LOCALES = ['en-GB', 'de-DE', 'fr-FR', 'it-IT', 'nl-NL', 'es-ES', 'pl-PL'];

    private function __construct()
    {
    }

    /** Cards that only say a setup step is still open (the withdrawal button is not set up yet). */
    public const SETUP = ['withdrawal' => 'notReady'];

    /**
     * The cards that need a person now. Takes the cards as the Overview built them; anything
     * not shaped like a card is skipped.
     *
     * @return list<array{key: string, text: string, values: array<string, int|string>}>
     */
    public static function urgent(mixed $cards): array
    {
        $out = [];
        foreach (\is_array($cards) ? $cards : [] as $card) {
            if (!\is_array($card) || !\is_string($card['key'] ?? null) || !\is_string($card['state'] ?? null) || !\is_string($card['text'] ?? null)) {
                continue;
            }
            if ((Overview::UNCHECKED[$card['key']] ?? null) === $card['text']) {
                continue;
            }
            // A setup step is shown on the page, never mailed: a fresh install would be told at once.
            if ((self::SETUP[$card['key']] ?? null) === $card['text']) {
                continue;
            }
            if ($card['state'] !== 'bad' && !($card['key'] === 'refunds' && $card['state'] === 'warn')) {
                continue;
            }
            $values = [];
            foreach (\is_array($card['values'] ?? null) ? $card['values'] : [] as $name => $value) {
                if (\is_string($name) && (\is_int($value) || \is_string($value))) {
                    $values[$name] = $value;
                }
            }
            $out[] = ['key' => $card['key'], 'text' => $card['text'], 'values' => $values];
        }

        return $out;
    }

    /**
     * The same items with the same numbers give the same digest.
     *
     * @param list<array{key: string, text: string, values: array<string, int|string>}> $items
     */
    public static function digest(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $values = $item['values'];
            ksort($values);
            $parts[] = $item['key'] . "\t" . $item['text'] . "\t" . json_encode($values);
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @param list<array{key: string, text: string, values: array<string, int|string>}> $items
     * @param array{off: bool, time: int, digest: string} $state
     */
    public static function due(array $items, array $state, int $now): bool
    {
        if ($state['off'] || $items === []) {
            return false;
        }
        if (self::digest($items) !== $state['digest']) {
            return true;
        }

        return $state['time'] <= $now - self::REPEAT_DAYS * 86400;
    }

    /**
     * The snippet file for a locale code such as "de-DE" or "de_AT": the exact one, else the same
     * language, else English.
     */
    public static function locale(string $code): string
    {
        $code = str_replace('_', '-', $code);
        if (\in_array($code, self::LOCALES, true)) {
            return $code;
        }
        foreach (self::LOCALES as $locale) {
            if (strtolower(substr($code, 0, 3)) === strtolower(substr($locale, 0, 2)) . '-') {
                return $locale;
            }
        }

        return 'en-GB';
    }

    /**
     * Subject and plain text body.
     *
     * @param list<array{key: string, text: string, values: array<string, int|string>}> $items
     * @param array<string, mixed> $snippets one decoded admin snippet file
     * @param array<string, mixed> $english the English snippet file, for anything missing
     *
     * @return array{subject: string, body: string}
     */
    public static function message(array $items, array $snippets, array $english, string $shop, string $url): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = self::either($snippets, $english, "shopproof.setup.card.{$item['key']}.title", []) . ': '
                . self::either($snippets, $english, "shopproof.setup.card.{$item['key']}.{$item['text']}", $item['values']);
        }

        return [
            'subject' => self::either($snippets, $english, 'shopproof.quietEmail.subject', ['count' => \count($items), 'shop' => $shop]),
            'body' => implode("\n\n", [
                self::either($snippets, $english, 'shopproof.quietEmail.intro', []),
                implode("\n\n", $lines),
                self::either($snippets, $english, 'shopproof.quietEmail.overview', ['url' => $url]),
                self::either($snippets, $english, 'shopproof.quietEmail.footer', []),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $snippets
     * @param array<string, mixed> $english
     * @param array<string, int|string> $values
     */
    private static function either(array $snippets, array $english, string $key, array $values): string
    {
        return self::text($snippets, $key, $values) ?? self::text($english, $key, $values) ?? $key;
    }

    /**
     * One snippet with its values, choosing "one | many" by the count as the admin does.
     *
     * @param array<string, mixed> $snippets
     * @param array<string, int|string> $values
     */
    public static function text(array $snippets, string $key, array $values = []): ?string
    {
        $node = $snippets;
        foreach (explode('.', $key) as $part) {
            if (!\is_array($node) || !\array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        if (!\is_string($node)) {
            return null;
        }
        $choices = array_map('trim', explode(' | ', $node));
        if (\count($choices) > 1 && isset($values['count'])) {
            $count = (int) $values['count'];
            $node = \count($choices) === 2 ? $choices[$count === 1 ? 0 : 1] : $choices[min($count, \count($choices) - 1)];
        } else {
            $node = $choices[0];
        }
        foreach ($values as $name => $value) {
            $node = str_replace('{' . $name . '}', (string) $value, $node);
        }

        return $node;
    }
}
