<?php declare(strict_types=1);

namespace Shopproof\Compliance\Storefront;

/**
 * Hazard labelling for hazardous chemicals sold online (CLP Regulation (EC) No 1272/2008,
 * Art. 48a: the offer shows the label elements clearly and visibly; rule EU-PS-03, decision 0025).
 * The shop enters pictograms, the signal word and statement codes per product (custom fields);
 * this class turns them into the official texts in the shopper's language from hazards.json,
 * built from the reviewed rules data. Same behaviour as the WooCommerce plugin's Shopproof_Hazards.
 */
final class HazardLabel
{
    /** Custom field prefix on products, shared with the product safety fields. */
    public const PREFIX = 'shopproof_safety_';

    /** Product custom fields, after PREFIX. */
    public const FIELDS = ['hazard_pictograms', 'hazard_signal', 'hazard_statements', 'precautionary_statements'];

    public const PICTOGRAMS = ['GHS01', 'GHS02', 'GHS03', 'GHS04', 'GHS05', 'GHS06', 'GHS07', 'GHS08', 'GHS09'];

    public const SIGNAL_WORDS = ['danger', 'warning'];

    public const LANGUAGES = ['en', 'de', 'fr', 'it', 'nl'];

    /** @var array<mixed>|null */
    private ?array $data = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * The label elements for a product, or null when it has none.
     *
     * @param array<mixed>|null $fields    the product's custom fields
     * @param string            $localeCode page locale, such as de-DE
     * @param string            $fallback  language of the shop's country, for a page language without texts
     * @param string            $assetBase URL of the pictogram folder, ending in "/"
     *
     * @return array{lang: string, pictograms: list<array{code: string, src: string, alt: string}>, signalWord: string, statements: list<array{code: string, text: string}>, precautions: list<array{code: string, text: string}>}|null
     */
    public function forProduct(?array $fields, string $localeCode, string $fallback, string $assetBase): ?array
    {
        $lang = self::language($localeCode, $fallback);
        $get = static fn (string $key): mixed => \is_array($fields) ? ($fields[self::PREFIX . $key] ?? null) : null;

        $pictograms = [];
        $names = $this->group('pictograms');
        foreach (self::cleanPictograms($get('hazard_pictograms')) as $code) {
            $entry = $names[$code] ?? null;
            if (!\is_array($entry) || !\is_string($entry['file'] ?? null)) {
                continue;
            }
            $name = \is_array($entry['name'] ?? null) && \is_string($entry['name'][$lang] ?? null) ? $entry['name'][$lang] : $code;
            $pictograms[] = [
                'code' => $code,
                'src' => $assetBase . $entry['file'],
                // The official symbol name in the shopper's language, from Annex V of CLP.
                'alt' => $name . ' (' . $code . ')',
            ];
        }
        $signal = self::cleanSignal($get('hazard_signal'));
        $words = $this->group('signal_words');
        $word = $signal !== '' && \is_array($words[$signal] ?? null) && \is_string($words[$signal][$lang] ?? null) ? $words[$signal][$lang] : '';
        $statements = $this->statements(self::text($get('hazard_statements')), 'hazard_statements', $lang);
        $precautions = $this->statements(self::text($get('precautionary_statements')), 'precautionary_statements', $lang);
        if ($pictograms === [] && $word === '' && $statements === [] && $precautions === []) {
            return null;
        }

        return ['lang' => $lang, 'pictograms' => $pictograms, 'signalWord' => $word, 'statements' => $statements, 'precautions' => $precautions];
    }

    /**
     * Lines the storefront cannot show, as [code, reason] with reason 'own' (the statement has a
     * choice or a blank and needs the shop's own words) or 'unknown'. The Shopproof page lists them.
     *
     * @param array<mixed>|null $fields the product's custom fields
     *
     * @return list<array{code: string, reason: string}>
     */
    public function problems(?array $fields): array
    {
        $own = $this->data()['needs_own_text'] ?? [];
        $problems = [];
        foreach (['hazard_statements', 'precautionary_statements'] as $group) {
            $table = $this->group($group);
            $raw = self::text(\is_array($fields) ? ($fields[self::PREFIX . $group] ?? null) : null);
            foreach (self::parse($raw) as $line) {
                if ($line['code'] === '' || $line['text'] !== '' || isset($table[$line['code']])) {
                    continue;
                }
                $problems[] = ['code' => $line['code'], 'reason' => \is_array($own) && \in_array($line['code'], $own, true) ? 'own' : 'unknown'];
            }
        }

        return $problems;
    }

    /**
     * Pictogram codes from a list (multi select) or text such as "GHS02, ghs07": known codes only,
     * in the official order.
     *
     * @return list<string>
     */
    public static function cleanPictograms(mixed $value): array
    {
        if (\is_array($value)) {
            $value = implode(',', array_filter($value, 'is_string'));
        }
        if (!\is_string($value)) {
            return [];
        }
        $given = ',' . strtoupper((string) preg_replace('/\s+/', '', $value)) . ',';

        return array_values(array_filter(self::PICTOGRAMS, static fn (string $code): bool => str_contains($given, ',' . $code . ',')));
    }

    /**
     * The signal word key ('danger' or 'warning'), or '' when there is none.
     */
    public static function cleanSignal(mixed $value): string
    {
        $value = \is_string($value) ? strtolower(trim($value)) : '';

        return \in_array($value, self::SIGNAL_WORDS, true) ? $value : '';
    }

    /**
     * Statement lines: "H225" or "EUH208: Contains linalool. May produce an allergic reaction.".
     * Codes are written the official way (upper case, "+" without spaces, as in P305+P351+P338).
     * A line that is not a code is the shop's own text.
     *
     * @return list<array{code: string, text: string}>
     */
    public static function parse(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line, 2);
            $code = strtoupper((string) preg_replace('/\s*\+\s*/', '+', trim($parts[0])));
            if (preg_match('/^(EUH\d{3}A?|H\d{3}[A-Za-z]{0,2}|P\d{3})(\+(EUH\d{3}A?|H\d{3}|P\d{3}))*$/', $code) !== 1) {
                $out[] = ['code' => '', 'text' => $line];
                continue;
            }
            $out[] = ['code' => $code, 'text' => isset($parts[1]) ? trim($parts[1]) : ''];
        }

        return $out;
    }

    /**
     * Language of the official texts: the page language when there are texts for it, else the
     * language of the shop's country, else English.
     */
    public static function language(string $localeCode, string $fallback): string
    {
        $page = strtolower(substr($localeCode, 0, 2));
        if (\in_array($page, self::LANGUAGES, true)) {
            return $page;
        }

        return \in_array($fallback, self::LANGUAGES, true) ? $fallback : 'en';
    }

    /**
     * The statements to show: the shop's own text where given, else the official text. Lines that
     * have neither are left out (problems() lists them).
     *
     * @return list<array{code: string, text: string}>
     */
    private function statements(string $raw, string $group, string $lang): array
    {
        $table = $this->group($group);
        $out = [];
        foreach (self::parse($raw) as $line) {
            $text = $line['text'];
            if ($text === '' && \is_array($table[$line['code']] ?? null)) {
                $texts = $table[$line['code']];
                $text = \is_string($texts[$lang] ?? null) ? $texts[$lang] : (\is_string($texts['en'] ?? null) ? $texts['en'] : '');
            }
            if ($text !== '') {
                $out[] = ['code' => $line['code'], 'text' => $text];
            }
        }

        return $out;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * @return array<mixed>
     */
    private function group(string $key): array
    {
        $group = $this->data()[$key] ?? null;

        return \is_array($group) ? $group : [];
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        if ($this->data === null) {
            $raw = is_file($this->file) ? file_get_contents($this->file) : false;
            $decoded = \is_string($raw) ? json_decode($raw, true) : null;
            $this->data = \is_array($decoded) ? $decoded : [];
        }

        return $this->data;
    }
}
