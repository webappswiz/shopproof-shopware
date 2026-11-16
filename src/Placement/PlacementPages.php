<?php declare(strict_types=1);

namespace Shopproof\Compliance\Placement;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Withdrawal\SetupFacts;
use Shopproof\Compliance\Withdrawal\Wording;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The shop pages the placement check opens, per active storefront: home, the newest product, the
 * first category, the cart, the customer account login and the withdrawal page itself (which
 * needs no link to itself). Each address carries a fresh preview key, so the Shopproof page may
 * show it in a frame. Also the withdrawal button labels and page paths the check looks for.
 * Only reads the shop's own database; nothing leaves the shop.
 */
final class PlacementPages
{
    /** Shopware's own button wording, for shops that changed no snippets. */
    private const CORE_LABELS = ['Revoke a contract', 'Vertrag widerrufen'];

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
        private readonly PreviewToken $token,
        private readonly Wording $wording,
        private readonly string $snippetDir,
    ) {
    }

    /**
     * @param string $adminOrigin scheme, host and port the admin runs on (the frame only opens pages
     *                            of that origin)
     *
     * @return array{channels: list<array{id: string, name: string, base: string, sameOrigin: bool, pages: list<array{key: string, url: string, expectLink: bool}>, paths: list<string>}>, labels: list<string>}
     */
    public function collect(int $now, string $adminOrigin = ''): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sc.id)) AS id, LOWER(HEX(sc.navigation_category_id)) AS navigation, LOWER(HEX(sc.language_id)) AS language,
                    COALESCE(sct.name, \'\') AS name
             FROM sales_channel sc
             LEFT JOIN sales_channel_translation sct ON sct.sales_channel_id = sc.id AND sct.language_id = sc.language_id
             WHERE sc.type_id = :type AND sc.active = 1
             ORDER BY sct.name',
            ['type' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]
        );

        $channels = [];
        $labels = self::CORE_LABELS;
        foreach ($rows as $row) {
            $id = self::str($row['id'] ?? null);
            $language = self::str($row['language'] ?? null);
            $domain = $id === '' ? null : self::pickDomain($this->domains($id, $language), $adminOrigin);
            if ($domain === null) {
                continue;
            }
            $base = $domain['url'];
            $pages = [['key' => 'home', 'path' => '/', 'expectLink' => true]];
            $product = $this->product($id);
            if ($product !== '') {
                $pages[] = ['key' => 'product', 'path' => $this->seoPath($id, $language, 'frontend.detail.page', $product) ?? '/detail/' . $product, 'expectLink' => true];
            }
            $category = $this->category(self::str($row['navigation'] ?? null));
            if ($category !== '') {
                $pages[] = ['key' => 'listing', 'path' => $this->seoPath($id, $language, 'frontend.navigation.page', $category) ?? '/navigation/' . $category, 'expectLink' => true];
            }
            $pages[] = ['key' => 'cart', 'path' => '/checkout/cart', 'expectLink' => true];
            $pages[] = ['key' => 'account', 'path' => '/account/login', 'expectLink' => true];

            $paths = [];
            $pageId = $this->config->get(SetupFacts::PAGE, $id);
            if (\is_string($pageId) && Uuid::isValid($pageId)) {
                $pageId = strtolower($pageId);
                $paths[] = '/page/cms/' . $pageId;
                $seo = $this->categoryWithPage($id, $language, $pageId);
                if ($seo !== null) {
                    $paths[] = $seo;
                }
                $pages[] = ['key' => 'withdrawal', 'path' => $seo ?? '/page/cms/' . $pageId, 'expectLink' => false];
            }

            foreach (['en', 'de', 'fr', 'it', 'nl'] as $lang) {
                $own = $this->wording->labels($id, $lang);
                if ($own !== null) {
                    $labels[] = $own['withdraw'];
                }
            }

            $channels[] = [
                'id' => $id,
                'name' => self::str($row['name'] ?? null),
                'base' => $base,
                'sameOrigin' => $domain['sameOrigin'],
                'pages' => array_map(fn (array $p): array => [
                    'key' => $p['key'],
                    'url' => $this->token->url($base . $p['path'], $now),
                    'expectLink' => $p['expectLink'],
                ], $pages),
                'paths' => array_values(array_unique(array_map(static fn (string $p): string => self::pathOf($base) . rtrim($p, '/'), $paths))),
            ];
        }

        return ['channels' => $channels, 'labels' => array_values(array_unique([...$labels, ...$this->snippetLabels()]))];
    }

    /**
     * The national button wording from the plugin's storefront snippets, every language.
     *
     * @return list<string>
     */
    public function snippetLabels(): array
    {
        $labels = [];
        foreach (glob($this->snippetDir . '/shopproof.*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            $html = \is_array($data) && \is_array($data['footer'] ?? null) ? ($data['footer']['serviceRevocationRequestTextPage'] ?? null) : null;
            if (\is_string($html)) {
                $text = trim(html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5));
                if ($text !== '') {
                    $labels[] = $text;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * The storefront domain to check: the first one on the admin's own origin, because the admin
     * frame can only show pages of that origin, otherwise the first in the given order (storefront
     * language first, then oldest). Null when the storefront has no http or https domain.
     *
     * @param list<string> $urls
     *
     * @return array{url: string, sameOrigin: bool}|null
     */
    public static function pickDomain(array $urls, string $adminOrigin): ?array
    {
        $usable = [];
        foreach ($urls as $url) {
            $url = rtrim(trim($url), '/');
            if (preg_match('#^https?://#i', $url)) {
                $usable[] = $url;
            }
        }
        if ($usable === []) {
            return null;
        }
        $admin = self::originOf($adminOrigin);
        foreach ($usable as $url) {
            if ($admin !== '' && self::originOf($url) === $admin) {
                return ['url' => $url, 'sameOrigin' => true];
            }
        }

        return ['url' => $usable[0], 'sameOrigin' => false];
    }

    /** scheme://host[:port] in lower case, with the default port left out; empty when unreadable. */
    public static function originOf(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . strtolower($parts['host']) . ($port !== null && $port !== $default ? ':' . $port : '');
    }

    /** The path part of a domain such as https://shop.example/en, without the end slash. */
    public static function pathOf(string $base): string
    {
        $path = parse_url($base, \PHP_URL_PATH);

        return \is_string($path) ? rtrim($path, '/') : '';
    }

    /**
     * A storefront's domains, its own language first, then the oldest.
     *
     * @return list<string>
     */
    private function domains(string $channel, string $language): array
    {
        $urls = $this->connection->fetchFirstColumn(
            'SELECT d.url FROM sales_channel_domain d WHERE d.sales_channel_id = :channel
             ORDER BY d.language_id = :language DESC, d.created_at ASC',
            ['channel' => Uuid::fromHexToBytes($channel), 'language' => Uuid::isValid($language) ? Uuid::fromHexToBytes($language) : '']
        );

        return array_values(array_filter($urls, 'is_string'));
    }

    private function product(string $channel): string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(p.id)) FROM product p
             JOIN product_visibility v ON v.product_id = p.id AND v.product_version_id = p.version_id AND v.sales_channel_id = :channel AND v.visibility = 30
             WHERE p.version_id = :version AND p.parent_id IS NULL AND p.active = 1
             ORDER BY p.created_at DESC LIMIT 1',
            ['channel' => Uuid::fromHexToBytes($channel), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        return \is_string($id) ? $id : '';
    }

    private function category(string $root): string
    {
        if (!Uuid::isValid($root)) {
            return '';
        }
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(c.id)) FROM category c
             WHERE c.parent_id = :root AND c.version_id = :version AND c.active = 1 AND c.visible = 1 AND c.type = \'page\'
             ORDER BY c.auto_increment ASC LIMIT 1',
            ['root' => Uuid::fromHexToBytes($root), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        return \is_string($id) ? $id : '';
    }

    /** The SEO address of a category whose layout is the withdrawal page, if there is one. */
    private function categoryWithPage(string $channel, string $language, string $pageId): ?string
    {
        $category = $this->connection->fetchOne(
            'SELECT LOWER(HEX(c.id)) FROM category c WHERE c.cms_page_id = :page AND c.version_id = :version AND c.active = 1 LIMIT 1',
            ['page' => Uuid::fromHexToBytes($pageId), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        return \is_string($category) ? $this->seoPath($channel, $language, 'frontend.navigation.page', $category) : null;
    }

    private function seoPath(string $channel, string $language, string $route, string $id): ?string
    {
        if (!Uuid::isValid($language)) {
            return null;
        }
        $path = $this->connection->fetchOne(
            'SELECT seo_path_info FROM seo_url
             WHERE foreign_key = :id AND route_name = :route AND sales_channel_id = :channel AND language_id = :language
               AND is_canonical = 1 AND is_deleted = 0
             LIMIT 1',
            [
                'id' => Uuid::fromHexToBytes($id),
                'route' => $route,
                'channel' => Uuid::fromHexToBytes($channel),
                'language' => Uuid::fromHexToBytes($language),
            ]
        );

        return \is_string($path) && $path !== '' ? '/' . ltrim($path, '/') : null;
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
