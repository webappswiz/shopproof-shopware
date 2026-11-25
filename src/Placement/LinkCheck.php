<?php declare(strict_types=1);

namespace Shopproof\Compliance\Placement;

/**
 * Weekly link check (zero-thought plan item 8), as in the WooCommerce plugin: once a week the
 * shop's own server opens the home page and a product page of each storefront as a visitor would
 * and looks for the withdrawal link, so a theme change that removed it is noticed. Only the shop's
 * own storefront addresses are opened; nothing goes to another server. A page that does not open
 * (an error, maintenance mode) counts as not checked, never as missing. The browser
 * check on the Shopproof page stays the full check; this one only looks for the link. Pure.
 */
final class LinkCheck
{
    /** System config key: {time, found, missing, unchecked}. */
    public const CONFIG = 'ShopproofCompliance.linkCheck';

    private function __construct()
    {
    }

    /** Words other apps use on their withdrawal buttons, the same as the browser check (placement.mjs). */
    private const WITHDRAW_WORDS = '/\\b(widerruf|widerrufen|withdraw|withdrawal|renoncer|rétractation|retractation|recedere|recesso|herroep|herroepen|ontbinden)\\b/iu';

    /**
     * Whether a page's HTML holds the withdrawal link, by the same rules as the browser check on the
     * Shopproof page (placement.mjs): found means Shopproof's own link or a link to the withdrawal
     * page of the storefront (its path, or that path behind one short language segment such as
     * "/de"). A link or button that only has a withdrawal label or word (a footer link to the
     * withdrawal policy, Shopware's own button to another page) cannot tell whether the button is
     * still there, so the page counts as not checked (null), never as missing.
     *
     * @param list<string> $paths  paths of the withdrawal page, without a trailing slash
     * @param list<string> $labels withdrawal button labels
     *
     * @return bool|null true found, false missing, null not checked
     */
    public static function hasLink(string $html, array $paths, array $labels = []): ?bool
    {
        if (trim($html) === '') {
            return false;
        }
        $paths = array_values(array_filter(array_map(static fn (string $p): string => self::path($p), $paths), static fn (string $p): bool => $p !== '' && $p !== '/'));
        $known = array_values(array_filter(array_map(self::norm(...), $labels), static fn (string $l): bool => $l !== ''));
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return false;
        }
        $lookAlike = false;
        $nodes = (new \DOMXPath($doc))->query('//a[@href] | //button | //*[@role="button"] | //*[@role="link"]');
        foreach ($nodes === false ? [] : $nodes as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            if (str_contains(' ' . $el->getAttribute('class') . ' ', ' shopproof-withdraw-link ')) {
                return true;
            }
            if ($el->tagName === 'a' && self::toPage($el->getAttribute('href'), $paths)) {
                return true;
            }
            $text = self::norm($el->textContent !== '' && trim($el->textContent) !== '' ? $el->textContent : ($el->getAttribute('aria-label') ?: $el->getAttribute('title')));
            if ($text !== '' && (\in_array($text, $known, true) || (mb_strlen($text) <= 60 && preg_match(self::WITHDRAW_WORDS, $text) === 1))) {
                $lookAlike = true;
            }
        }

        return $lookAlike ? null : false;
    }

    /**
     * @return array{time: int, found: int, missing: int, unchecked: int}|null
     */
    public static function read(mixed $stored): ?array
    {
        if (!\is_array($stored) || !\is_int($stored['time'] ?? null) || $stored['time'] <= 0) {
            return null;
        }
        $out = ['time' => $stored['time'], 'found' => 0, 'missing' => 0, 'unchecked' => 0];
        foreach (['found', 'missing', 'unchecked'] as $key) {
            $out[$key] = \is_int($stored[$key] ?? null) ? max(0, $stored[$key]) : 0;
        }

        return $out;
    }

    /**
     * Pages where the link is missing in the weekly check, when that check is newer than the last
     * browser check (which then wins). Zero otherwise.
     *
     * @param array{time: int, found: int, missing: int, unchecked: int}|null $check
     * @param array{time: int}|null $placement
     */
    public static function missingSince(?array $check, ?array $placement): int
    {
        if ($check === null || $check['missing'] < 1) {
            return 0;
        }
        if ($placement !== null && $placement['time'] >= $check['time']) {
            return 0;
        }

        return $check['missing'];
    }

    /** @param list<string> $paths */
    private static function toPage(string $href, array $paths): bool
    {
        $path = self::path($href);
        if ($path === '' || $path === '/') {
            return false;
        }
        foreach ($paths as $p) {
            // The path itself, or behind one language segment ("/de", "/en-gb"), never any longer prefix.
            if ($path === $p || (str_ends_with($path, $p) && preg_match('#^/[a-z]{2}(-[a-z]{2})?$#', substr($path, 0, -\strlen($p))) === 1)) {
                return true;
            }
        }

        return false;
    }

    private static function norm(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'))));
    }

    private static function path(string $url): string
    {
        $path = parse_url(html_entity_decode($url), \PHP_URL_PATH);

        return \is_string($path) ? '/' . trim(strtolower($path), '/') : '';
    }
}
