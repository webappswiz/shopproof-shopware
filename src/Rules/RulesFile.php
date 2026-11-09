<?php declare(strict_types=1);

namespace Shopproof\Compliance\Rules;

/**
 * Reads the shared Shopproof rules file. The same file is used by the Shopify app and the
 * WooCommerce plugin. Pure functions, no Shopware dependencies, so they are unit tested alone.
 */
final class RulesFile
{
    public const URL = 'https://d3jn3nfc6dj1sm.cloudfront.net/rules/v1/rules.json';
    public const SUPPORTED_SCHEMA = 1;
    public const LAUNCH_COUNTRIES = ['EU', 'AT', 'DE', 'FR', 'IT', 'NL'];
    public const DEFAULT_DISCLAIMER = 'Technical information to help shops comply. Not legal advice.';

    private function __construct()
    {
    }

    /**
     * Decode and sanity check a rules file. It comes from the network, so nothing in it is
     * trusted: the schema must be the one this code understands and every rule must be an object.
     *
     * @return array<string, mixed>|null the decoded file, or null if it is not usable
     */
    public static function parse(string $body): ?array
    {
        try {
            $file = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($file) || !isset($file['rules']) || !\is_array($file['rules'])) {
            return null;
        }
        if (($file['schema'] ?? null) !== self::SUPPORTED_SCHEMA) {
            return null;
        }
        if (!isset($file['version']) || !\is_string($file['version']) || $file['version'] === '') {
            return null;
        }
        foreach ($file['rules'] as $rule) {
            if (!\is_array($rule)) {
                return null;
            }
        }

        /** @var array<string, mixed> $file */
        return $file;
    }

    /**
     * Rules a merchant may see: never ones held for review. A rule is shown only when it says
     * explicitly that no review is required.
     *
     * @param array<string, mixed>|null $file
     *
     * @return list<array<string, mixed>>
     */
    public static function visible(?array $file): array
    {
        $rules = (\is_array($file) && isset($file['rules']) && \is_array($file['rules'])) ? $file['rules'] : [];
        $shown = [];
        foreach ($rules as $rule) {
            if (\is_array($rule) && \array_key_exists('review_required', $rule) && $rule['review_required'] === false) {
                /** @var array<string, mixed> $rule */
                $shown[] = $rule;
            }
        }

        return $shown;
    }

    /**
     * Visible rules for the given jurisdictions (EU-wide rules always included), in launch order.
     *
     * @param array<string, mixed>|null $file
     * @param list<string>              $countries
     *
     * @return list<array<string, mixed>>
     */
    public static function forCountries(?array $file, array $countries): array
    {
        $wanted = array_values(array_unique(['EU', ...array_map('strtoupper', $countries)]));
        $order = array_flip(self::LAUNCH_COUNTRIES);
        $rules = array_values(array_filter(
            self::visible($file),
            static fn (array $rule): bool => \in_array(self::string($rule, 'jurisdiction'), $wanted, true)
        ));
        usort($rules, static function (array $a, array $b) use ($order): int {
            $byCountry = ($order[self::string($a, 'jurisdiction')] ?? 99) <=> ($order[self::string($b, 'jurisdiction')] ?? 99);

            return $byCountry !== 0 ? $byCountry : strcmp(self::string($a, 'id'), self::string($b, 'id'));
        });

        return $rules;
    }

    /**
     * Count visible rules per jurisdiction, in launch order.
     *
     * @param array<string, mixed>|null $file
     *
     * @return array<string, int>
     */
    public static function summarize(?array $file): array
    {
        $counts = array_fill_keys(self::LAUNCH_COUNTRIES, 0);
        foreach (self::visible($file) as $rule) {
            $jurisdiction = self::string($rule, 'jurisdiction');
            $counts[$jurisdiction] = ($counts[$jurisdiction] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * A text field of the rules file, or the fallback when it is missing or not text.
     *
     * @param array<string, mixed>|null $file
     */
    public static function text(?array $file, string $key, string $fallback = ''): string
    {
        $value = \is_array($file) ? ($file[$key] ?? null) : null;

        return (\is_string($value) && $value !== '') ? $value : $fallback;
    }

    /**
     * The withdrawal button labels for one country from the rules strings, or null when the file
     * has none for that country.
     *
     * @param array<string, mixed>|null $file
     *
     * @return array{language: string, withdraw_button: string, confirm_button: string}|null
     */
    public static function withdrawalLabels(?array $file, string $country): ?array
    {
        $strings = $file['strings'] ?? null;
        $labels = \is_array($strings) ? ($strings[strtoupper($country)] ?? null) : null;
        if (!\is_array($labels)) {
            return null;
        }
        foreach (['language', 'withdraw_button', 'confirm_button'] as $key) {
            if (!isset($labels[$key]) || !\is_string($labels[$key]) || $labels[$key] === '') {
                return null;
            }
        }

        return [
            'language' => $labels['language'],
            'withdraw_button' => $labels['withdraw_button'],
            'confirm_button' => $labels['confirm_button'],
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    private static function string(array $rule, string $key): string
    {
        $value = $rule[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
