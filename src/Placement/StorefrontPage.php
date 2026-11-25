<?php declare(strict_types=1);

namespace Shopproof\Compliance\Placement;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Opens one storefront page as a visitor would, for the weekly link check. Redirects are followed
 * by hand, at most 3, and only on the same host: the same scheme and port, or the step from http on
 * port 80 to https on port 443 that most shops make. Any other target counts as not opened, so the
 * check never opens another server.
 */
final class StorefrontPage
{
    public const MAX_REDIRECTS = 3;

    private function __construct()
    {
    }

    /**
     * The page HTML, or null when it did not open as a shop page.
     */
    public static function get(ClientInterface $client, LoggerInterface $logger, string $url): ?string
    {
        if (self::origin($url) === null) {
            return null;
        }
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            try {
                $response = $client->request('GET', $url, [
                    'timeout' => 15,
                    'connect_timeout' => 5,
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'headers' => ['Cache-Control' => 'no-cache'],
                ]);
            } catch (\Throwable $e) {
                $logger->info('Shopproof weekly link check could not open a storefront page: ' . $e->getMessage());

                return null;
            }
            $status = $response->getStatusCode();
            if ($status >= 300 && $status < 400) {
                $next = self::resolve($url, $response->getHeaderLine('Location'));
                if ($next === null || !self::sameShop($url, $next)) {
                    return null;
                }
                $url = $next;

                continue;
            }
            if ($status !== 200 || stripos($response->getHeaderLine('Content-Type'), 'html') === false) {
                return null;
            }

            return (string) $response->getBody();
        }

        return null;
    }

    /** Whether a redirect stays on the shop: the same origin, or http:80 to https:443 on the same host. */
    public static function sameShop(string $from, string $to): bool
    {
        $a = self::origin($from);
        $b = self::origin($to);
        if ($a === null || $b === null) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $host = strtolower((string) parse_url($from, \PHP_URL_HOST));

        return $a === 'http://' . $host . ':80' && $b === 'https://' . $host . ':443';
    }

    /** Scheme, host and port of an absolute http(s) address, null for anything else. */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . $host . ':' . $port;
    }

    /** A redirect target as an absolute address, relative to the page that sent it. */
    public static function resolve(string $base, string $location): ?string
    {
        $location = trim($location);
        $parts = parse_url($base);
        if ($location === '' || !\is_array($parts) || self::origin($base) === null) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $prefix = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '/';
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $prefix . $location;
        }
        if (str_starts_with($location, '?')) {
            return $prefix . $path . $location;
        }
        $dir = (string) preg_replace('#/[^/]*$#', '/', $path);

        return $prefix . ($dir === '' ? '/' : $dir) . $location;
    }
}
