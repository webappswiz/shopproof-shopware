<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

/**
 * The withdrawal line at the very end of the order confirmation and shipping emails, after the
 * shop's own closing words and contact details (owner's placement rule, 7 Nov 2026):
 * "Withdraw from contract here. Changed your mind? You can withdraw within 14 days.", both parts
 * linking to the shop's withdrawal page. The wording per language comes from email-line.json,
 * built from the reviewed national button labels. Pure, so it is unit tested without Shopware.
 */
final class OrderEmailLine
{
    /** Marker class, so the line is never added twice and a pasted copy is respected. */
    public const MARKER = 'shopproof-email-link';

    /** Marker class of the status page line in Shopware's receipt email. */
    public const STATUS_MARKER = 'shopproof-status-link';
    public const RETURNS_MARKER = 'shopproof-returns-link';
    public const ITEMS_MARKER = 'shopproof-items';
    public const EXCLUDED_MARKER = 'shopproof-excluded';

    /** @var array<mixed>|null */
    private ?array $data = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * The withdrawal period a setting gives: 14 to 365 days, else the legal minimum of 14.
     */
    public static function period(int $days): int
    {
        return $days >= 14 && $days <= 365 ? $days : 14;
    }

    /**
     * The words for a language, or null when there is no reviewed wording for it.
     *
     * @return array{lang: string, link: string, question: string, period: string}|null
     */
    public function words(string $localeCode, int $days): ?array
    {
        $lang = strtolower(substr($localeCode, 0, 2));
        $entry = $this->languages()[$lang] ?? null;
        if (!\is_array($entry) || !\is_string($entry['link'] ?? null) || !\is_string($entry['question'] ?? null) || !\is_string($entry['period'] ?? null)) {
            return null;
        }

        return [
            'lang' => $lang,
            'link' => $entry['link'],
            'question' => $entry['question'],
            'period' => str_replace('{days}', (string) $days, $entry['period']),
        ];
    }

    /**
     * Adds the line to an email's HTML and plain text templates. The HTML goes inside the
     * template's last closing </div> when it ends with one (Shopware's own templates wrap the
     * message in a div that sets the font), else at the end. Text that would be read as Twig is
     * escaped, because Shopware renders the templates after this.
     *
     * @param array{lang: string, link: string, question: string, period: string} $words
     *
     * @return array{html: string, plain: string}
     */
    public static function add(string $html, string $plain, array $words, string $url): array
    {
        if (str_contains($html, self::MARKER)) {
            return ['html' => $html, 'plain' => $plain];
        }
        $e = static fn (string $text): string => str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $href = $e($url);
        $line = '<p class="' . self::MARKER . '" style="margin-top:2em;"><a href="' . $href . '">' . $e($words['link']) . '</a>. '
            . $e($words['question']) . ' <a href="' . $href . '">' . $e($words['period']) . '</a></p>';

        $trimmed = rtrim($html);
        if (str_ends_with(strtolower($trimmed), '</div>')) {
            $html = substr($trimmed, 0, -6) . $line . "\n</div>\n";
        } else {
            $html = $trimmed . "\n" . $line . "\n";
        }

        $safe = static fn (string $text): string => str_replace(['{{', '{%', '{#'], ['{ {', '{ %', '{ #'], $text);
        $plain = rtrim($plain) . "\n\n" . $safe($words['link']) . ': ' . $safe($url) . "\n" . $safe($words['question'] . ' ' . $words['period']) . "\n";

        return ['html' => $html, 'plain' => $plain];
    }

    /**
     * The words before the status page link for a language, or null.
     */
    public function statusWords(string $localeCode): ?string
    {
        return $this->sentence($localeCode, 'status');
    }

    /**
     * The returns portal sentence ("Start your return here:") for a locale, null without one.
     */
    public function returnsWords(string $localeCode): ?string
    {
        return $this->sentence($localeCode, 'returns');
    }

    /**
     * The items label ("Items") for a locale, null without one.
     */
    public function itemsLabel(string $localeCode): ?string
    {
        return $this->sentence($localeCode, 'items');
    }

    /**
     * The shop copy's line naming excluded items, with "%s" for the names; English by default.
     */
    public function excludedLine(string $localeCode): ?string
    {
        return $this->sentence($localeCode, 'excluded') ?? $this->sentence('en', 'excluded');
    }

    /**
     * Adds a paragraph of plain text (the items, or the excluded items) to a withdrawal email,
     * once. Line breaks in the text stay line breaks.
     *
     * @return array{html: string, plain: string}
     */
    public static function addText(string $html, string $plain, string $text, string $marker): array
    {
        if (str_contains($html, $marker) || trim($text) === '') {
            return ['html' => $html, 'plain' => $plain];
        }
        $e = static fn (string $value): string => str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $line = '<p class="' . $marker . '" style="margin-top:1em;">' . nl2br($e($text), false) . '</p>';
        $trimmed = rtrim($html);
        $html = str_ends_with(strtolower($trimmed), '</div>') ? substr($trimmed, 0, -6) . $line . "\n</div>\n" : $trimmed . "\n" . $line . "\n";
        $safe = static fn (string $value): string => str_replace(['{{', '{%', '{#'], ['{ {', '{ %', '{ #'], $value);

        return ['html' => $html, 'plain' => rtrim($plain) . "\n\n" . $safe($text) . "\n"];
    }

    private function sentence(string $localeCode, string $key): ?string
    {
        $entry = $this->languages()[strtolower(substr($localeCode, 0, 2))] ?? null;

        return \is_array($entry) && \is_string($entry[$key] ?? null) ? $entry[$key] : null;
    }

    /**
     * Adds the status page line to the receipt email, in the same place as the withdrawal line.
     *
     * @return array{html: string, plain: string}
     */
    public static function addStatus(string $html, string $plain, string $words, string $url): array
    {
        return self::addLink($html, $plain, $words, $url, self::STATUS_MARKER, '2em');
    }

    /**
     * Adds the returns portal line to the receipt email, after the status line.
     *
     * @return array{html: string, plain: string}
     */
    public static function addReturns(string $html, string $plain, string $words, string $url): array
    {
        return self::addLink($html, $plain, $words, $url, self::RETURNS_MARKER, str_contains($html, self::STATUS_MARKER) ? '1em' : '2em');
    }

    /**
     * @return array{html: string, plain: string}
     */
    private static function addLink(string $html, string $plain, string $words, string $url, string $marker, string $space): array
    {
        if (str_contains($html, $marker)) {
            return ['html' => $html, 'plain' => $plain];
        }
        $e = static fn (string $text): string => str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $line = '<p class="' . $marker . '" style="margin-top:' . $space . ';">' . $e($words) . ' <a href="' . $e($url) . '">' . $e($url) . '</a></p>';
        $trimmed = rtrim($html);
        $html = str_ends_with(strtolower($trimmed), '</div>') ? substr($trimmed, 0, -6) . $line . "\n</div>\n" : $trimmed . "\n" . $line . "\n";
        $safe = static fn (string $text): string => str_replace(['{{', '{%', '{#'], ['{ {', '{ %', '{ #'], $text);

        return ['html' => $html, 'plain' => rtrim($plain) . "\n\n" . $safe($words . ' ' . $url) . "\n"];
    }

    /**
     * @return array<mixed>
     */
    private function languages(): array
    {
        if ($this->data === null) {
            $raw = is_file($this->file) ? file_get_contents($this->file) : false;
            $decoded = \is_string($raw) ? json_decode($raw, true) : null;
            $this->data = \is_array($decoded) && \is_array($decoded['languages'] ?? null) ? $decoded['languages'] : [];
        }

        return $this->data;
    }
}
