<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

/**
 * Withdrawal policy match check (decision 0039): compares the withdrawal period and the email
 * address in the shop's own withdrawal policy page (Shopware's "Revocation page") with Shopproof's
 * withdrawal period and the shop email, so the form, the emails and the deadline clock say the
 * same as the policy. Works with any legal text provider, because it reads the page as published.
 *
 * Pure: the caller passes the plain text. The patterns (policy-match.json) come from
 * packages/widgets, and the WooCommerce plugin and the Shopify app run the same test cases.
 */
final class PolicyMatch
{
    public const MATCH = 'match';
    public const DIFFERENT = 'different';
    public const NOT_FOUND = 'not_found';
    public const UNSURE = 'unsure';
    public const NOT_CHECKED = 'not_checked';
    public const FOUND = 'found';

    /** Unicode spaces turned into one plain space, the same set the JavaScript version uses. */
    private const SPACES = '/[\s\x{00a0}\x{2000}-\x{200a}\x{202f}\x{205f}\x{3000}\x{feff}]+/u';

    /** @var array{minLength: int, maxLength: int, numberWords: array<string, int>, days: list<string>, month: list<string>, email: string} */
    private array $data;

    /** @var array{minPolicyWords: int, languages: array<string, array{copy: string, find: list<string>, variants: list<string>, policy_words: list<string>}>} */
    private array $online;

    /**
     * @param string $file   policy-match.json
     * @param string $online online-withdrawal.json (the online withdrawal sentence, rules EU-WD-02,
     *                       DE-WD-02, AT-WD-02); '' reads the file next to $file
     */
    public function __construct(string $file, string $online = '')
    {
        $online = $online !== '' ? $online : \dirname($file) . '/online-withdrawal.json';
        $raw = is_file($online) ? json_decode((string) file_get_contents($online), true) : null;
        $raw = \is_array($raw) ? $raw : [];
        $languages = [];
        foreach (\is_array($raw['languages'] ?? null) ? $raw['languages'] : [] as $lang => $l) {
            if (!\is_string($lang) || !\is_array($l) || !\is_string($l['copy'] ?? null)) {
                continue;
            }
            $languages[$lang] = [
                'copy' => $l['copy'],
                'find' => self::strings($l['find'] ?? null),
                'variants' => self::strings($l['variants'] ?? null),
                'policy_words' => self::strings($l['policy_words'] ?? null),
            ];
        }
        $this->online = ['minPolicyWords' => self::int($raw['minPolicyWords'] ?? 2), 'languages' => $languages];

        $decoded = json_decode((string) file_get_contents($file), true);
        $decoded = \is_array($decoded) ? $decoded : [];
        $this->data = [
            'minLength' => self::int($decoded['minLength'] ?? 200),
            'maxLength' => self::int($decoded['maxLength'] ?? 200000),
            'numberWords' => self::words($decoded['numberWords'] ?? null),
            'days' => array_values(array_filter(\is_array($decoded['days'] ?? null) ? $decoded['days'] : [], 'is_string')),
            'month' => array_values(array_filter(\is_array($decoded['month'] ?? null) ? $decoded['month'] : [], 'is_string')),
            'email' => \is_string($decoded['email'] ?? null) ? $decoded['email'] : '[a-z0-9._%+-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}',
        ];
    }

    /**
     * @return array{minLength: int, maxLength: int, numberWords: array<string, int>, days: list<string>, month: list<string>, email: string}
     */
    public function data(): array
    {
        return $this->data;
    }

    /** Lower case, single spaces, number words as digits. */
    public function normalise(string $text): string
    {
        $t = $text;
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($t, \Normalizer::FORM_C);
            $t = \is_string($n) ? $n : $t;
        }
        $t = mb_strtolower($t, 'UTF-8');
        $t = str_replace(["\u{2018}", "\u{2019}"], "'", $t);
        $t = trim((string) preg_replace(self::SPACES, ' ', $t));
        if (mb_strlen($t, 'UTF-8') > $this->data['maxLength']) {
            $t = mb_substr($t, 0, $this->data['maxLength'], 'UTF-8');
        }
        $words = array_keys($this->data['numberWords']);
        usort($words, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8'));
        foreach ($words as $word) {
            $t = (string) preg_replace('/\b' . $word . '\b/iu', (string) $this->data['numberWords'][$word], $t);
        }

        return $t;
    }

    /**
     * @param array{days: int, emails: list<string>} $settings Shopproof's period and the addresses withdrawals go to
     *
     * @return array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}
     */
    public function match(string $text, array $settings): array
    {
        $t = $this->normalise($text);
        if (mb_strlen($t, 'UTF-8') < $this->data['minLength']) {
            return [
                'checked' => false,
                'period' => ['status' => self::NOT_CHECKED, 'found' => [], 'month' => false],
                'email' => ['status' => self::NOT_CHECKED, 'found' => []],
            ];
        }
        $days = [];
        foreach ($this->data['days'] as $source) {
            if (preg_match_all('/' . $source . '/iu', $t, $m) > 0) {
                foreach ($m[1] as $n) {
                    if ((int) $n > 0) {
                        $days[(int) $n] = true;
                    }
                }
            }
        }
        $days = array_keys($days);
        sort($days);
        $month = false;
        foreach ($this->data['month'] as $source) {
            if (preg_match('/' . $source . '/iu', $t) === 1) {
                $month = true;
                break;
            }
        }
        if ($days === [] && !$month) {
            $period = self::NOT_FOUND;
        } elseif (\count($days) === 1 && !$month) {
            $period = $days[0] === $settings['days'] ? self::MATCH : self::DIFFERENT;
        } else {
            $period = self::UNSURE;
        }

        $found = [];
        if (preg_match_all('/' . $this->data['email'] . '/iu', $t, $m) > 0) {
            $found = array_values(array_unique($m[0]));
        }
        $ours = array_values(array_filter(array_map(static fn (string $e): string => strtolower(trim($e)), $settings['emails']), static fn (string $e): bool => $e !== ''));
        if ($found === []) {
            $email = self::NOT_FOUND;
        } elseif ($ours === []) {
            $email = self::UNSURE;
        } else {
            $email = array_intersect($found, $ours) !== [] ? self::MATCH : self::DIFFERENT;
        }

        return [
            'checked' => true,
            'period' => ['status' => $period, 'found' => $days, 'month' => $month],
            'email' => ['status' => $email, 'found' => $found],
        ];
    }

    /**
     * Does the policy say that the withdrawal function exists (the online withdrawal sentence of
     * the model instructions, or another wording that names the function)? Found, not found (only
     * for a withdrawal policy in a language the check knows well) or not checked.
     *
     * @return array{status: string, lang: string|null, reason: string|null}
     */
    public function online(string $text): array
    {
        $t = $this->normalise($text);
        if (mb_strlen($t, 'UTF-8') < $this->data['minLength']) {
            return ['status' => self::NOT_CHECKED, 'lang' => null, 'reason' => 'short'];
        }
        foreach ($this->online['languages'] as $lang => $l) {
            foreach ($l['find'] as $part) {
                if ($part !== '' && str_contains($t, $part)) {
                    return ['status' => self::FOUND, 'lang' => $lang, 'reason' => null];
                }
            }
            foreach ($l['variants'] as $source) {
                if (preg_match('/' . $source . '/iu', $t) === 1) {
                    return ['status' => self::FOUND, 'lang' => $lang, 'reason' => null];
                }
            }
        }
        $best = null;
        $most = 0;
        foreach ($this->online['languages'] as $lang => $l) {
            $n = 0;
            foreach ($l['policy_words'] as $word) {
                $n += substr_count($t, $word);
            }
            if ($n > $most) {
                $most = $n;
                $best = $lang;
            }
        }
        if ($best !== null && $most >= $this->online['minPolicyWords']) {
            return ['status' => self::NOT_FOUND, 'lang' => $best, 'reason' => null];
        }

        return ['status' => self::NOT_CHECKED, 'lang' => null, 'reason' => 'language'];
    }

    /**
     * The official sentence to copy into the policy, with the address of the withdrawal page.
     * English when the language is not in the data, '' when there is no data.
     */
    public function onlineSentence(string $lang, string $url): string
    {
        $l = $this->online['languages'][strtolower(substr($lang, 0, 2))] ?? $this->online['languages']['en'] ?? null;
        if ($l === null) {
            return '';
        }
        $url = trim($url);

        return str_replace('{url}', $url !== '' ? $url : '[URL]', $l['copy']);
    }

    /** @return array{minPolicyWords: int, languages: array<string, array{copy: string, find: list<string>, variants: list<string>, policy_words: list<string>}>} */
    public function onlineData(): array
    {
        return $this->online;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** Plain text from a CMS text element's HTML. */
    public static function plain(string $html): string
    {
        return html_entity_decode(strip_tags(str_replace('<', ' <', $html)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    /**
     * @return array<string, int>
     */
    private static function words(mixed $value): array
    {
        $words = [];
        foreach (\is_array($value) ? $value : [] as $word => $number) {
            if (\is_string($word) && is_numeric($number)) {
                $words[$word] = (int) $number;
            }
        }

        return $words;
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
