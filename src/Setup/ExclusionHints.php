<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Suggested withdrawal exclusions (zero-thought plan item 7), as on WooCommerce and Shopify: goods
 * in the shop's own catalogue that look like one of the legal exceptions to the right of
 * withdrawal (Art. 16 of Directive 2011/83/EU), offered on the Shopproof page for one tick each.
 * Nothing is excluded for the shop: only the ticked suggestions are saved, on the press, and
 * whether an exception applies stays the shop's call. Suggestions the shop does not want can be
 * hidden for good.
 *
 * - Digital products (Art. 16(m)): only while the download consent boxes are on, because the
 *   exception needs the customer's consent and acknowledgement.
 * - Made to order or personalised goods (Art. 16(c)) and sealed hygiene goods (Art. 16(e)):
 *   categories with products whose names say so, in the seven admin languages.
 *
 * Saved for all sales channels; a sales channel with its own list keeps it.
 */
final class ExclusionHints
{
    public const HIDDEN = 'ShopproofCompliance.exclusionHintsHidden';

    public const MAX = 10;

    /** Words in a category name, lower case, per exception. Same lists as the WooCommerce plugin. */
    public const WORDS = [
        'custom' => [
            'personalised',
            'personalized',
            'personalisation',
            'personalization',
            'custom-made',
            'custom made',
            'made to order',
            'made-to-order',
            'bespoke',
            'engrav',
            'personalisiert',
            'personalisierung',
            'individualisiert',
            'maßanfertigung',
            'nach maß',
            'gravur',
            'graviert',
            'personnalisé',
            'personnalisation',
            'sur mesure',
            'gravure',
            'personalizzat',
            'su misura',
            'incisione',
            'gepersonaliseerd',
            'op maat',
            'maatwerk',
            'personalizad',
            'a medida',
            'grabado',
            'personalizowan',
            'na zamówienie',
            'na wymiar',
            'grawer',
        ],
        'hygiene' => [
            'hygiene',
            'cosmetic',
            'make-up',
            'makeup',
            'skincare',
            'underwear',
            'lingerie',
            'intimate',
            'kosmetik',
            'unterwäsche',
            'dessous',
            'cosmétique',
            'maquillage',
            'sous-vêtement',
            'hygiène',
            'trucco',
            'intimo',
            'igiene',
            'cosmetica',
            'ondergoed',
            'hygiëne',
            'cosmética',
            'maquillaje',
            'ropa interior',
            'lencería',
            'higiene',
            'kosmety',
            'makijaż',
            'bielizna',
            'higien',
        ],
    ];

    private const CONFIG = 'ShopproofCompliance.config.';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
    ) {
    }

    /**
     * Which exception a category name looks like, or ''. Pure.
     */
    public static function kindOf(string $name): string
    {
        $name = mb_strtolower($name, 'UTF-8');
        foreach (self::WORDS as $kind => $words) {
            foreach ($words as $word) {
                if (str_contains($name, $word)) {
                    return $kind;
                }
            }
        }

        return '';
    }

    /**
     * What the catalogue and the settings say. Read only.
     *
     * @return array{digital: bool, consentOn: bool, excludeDigital: bool, excluded: list<string>, hidden: list<string>, categories: list<array{id: string, name: string, count: int}>}
     */
    public function facts(): array
    {
        $version = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(c.id)) AS id,
                COALESCE(t.name, (SELECT d.name FROM category_translation d WHERE d.category_id = c.id AND d.category_version_id = c.version_id AND d.name IS NOT NULL LIMIT 1), \'\') AS name,
                COUNT(DISTINCT pc.product_id) AS count
             FROM category c
             JOIN product_category pc ON pc.category_id = c.id AND pc.category_version_id = c.version_id
             LEFT JOIN category_translation t ON t.category_id = c.id AND t.category_version_id = c.version_id AND t.language_id = :language
             WHERE c.version_id = :version
             GROUP BY c.id, c.version_id, t.name
             ORDER BY name',
            ['version' => $version, 'language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]
        );
        $categories = [];
        foreach ($rows as $row) {
            if (\is_string($row['id'] ?? null) && \is_string($row['name'] ?? null) && is_numeric($row['count'] ?? null)) {
                $categories[$row['id']] = ['id' => $row['id'], 'name' => $row['name'], 'count' => (int) $row['count']];
            }
        }
        $hasType = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'product\' AND COLUMN_NAME = \'type\''
        ) > 0;
        $digital = $hasType && $this->connection->fetchOne(
            'SELECT 1 FROM product p LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
             WHERE p.version_id = :version AND COALESCE(p.active, parent.active) = 1 AND COALESCE(p.type, parent.type) = \'digital\' LIMIT 1',
            ['version' => $version]
        ) !== false;

        return [
            'digital' => $digital,
            'consentOn' => $this->config->getBool(self::CONFIG . 'digitalConsent'),
            'excludeDigital' => $this->config->getBool(self::CONFIG . 'excludeDigital'),
            'excluded' => self::ids($this->config->get(self::CONFIG . 'excludedCategories')),
            'hidden' => self::strings($this->config->get(self::HIDDEN)),
            'categories' => array_values($categories),
        ];
    }

    /**
     * The suggestions the facts call for, not yet excluded and not hidden. Pure.
     *
     * @param array<string, mixed> $facts
     *
     * @param int|null $max categories at most, null for all (used to hide every one at once)
     *
     * @return list<array{key: string, kind: string, name: string, count: int}>
     */
    public static function suggest(array $facts, ?int $max = self::MAX): array
    {
        $hidden = self::strings($facts['hidden'] ?? []);
        $excluded = self::ids($facts['excluded'] ?? []);
        $out = [];
        if (!empty($facts['digital']) && !empty($facts['consentOn']) && empty($facts['excludeDigital']) && !\in_array('digital', $hidden, true)) {
            $out[] = ['key' => 'digital', 'kind' => 'download', 'name' => '', 'count' => 0];
        }
        $categories = \is_array($facts['categories'] ?? null) ? $facts['categories'] : [];
        $found = 0;
        foreach ($categories as $cat) {
            if (!\is_array($cat) || !\is_string($cat['id'] ?? null) || !\is_string($cat['name'] ?? null)) {
                continue;
            }
            $id = strtolower($cat['id']);
            $kind = self::kindOf($cat['name']);
            $key = 'cat:' . $id;
            if ($kind === '' || \in_array($id, $excluded, true) || \in_array($key, $hidden, true) || ($max !== null && $found >= $max)) {
                continue;
            }
            $out[] = ['key' => $key, 'kind' => $kind, 'name' => $cat['name'], 'count' => \is_int($cat['count'] ?? null) ? $cat['count'] : 0];
            ++$found;
        }

        return $out;
    }

    /**
     * Excludes the ticked suggestions that are still offered, adding to the shop's own list.
     *
     * @param list<string> $keys
     *
     * @return list<string> the keys that were saved
     */
    public function apply(array $keys): array
    {
        $facts = $this->facts();
        $excluded = $facts['excluded'];
        $done = [];
        foreach (self::suggest($facts) as $hint) {
            if (!\in_array($hint['key'], $keys, true)) {
                continue;
            }
            if ($hint['key'] === 'digital') {
                $this->config->set(self::CONFIG . 'excludeDigital', true);
            } else {
                $excluded[] = substr($hint['key'], 4);
            }
            $done[] = $hint['key'];
        }
        if (\count($excluded) !== \count($facts['excluded'])) {
            $this->config->set(self::CONFIG . 'excludedCategories', array_values(array_unique($excluded)));
        }

        return $done;
    }

    /**
     * Hides every suggestion for good, also the ones past the first MAX that the page did not
     * show yet, so "Do not suggest these again" does not bring the next ones up. Excludes nothing.
     */
    public function hide(): int
    {
        $facts = $this->facts();
        $keys = array_column(self::suggest($facts, null), 'key');
        $this->config->set(self::HIDDEN, array_values(array_unique(array_merge($facts['hidden'], $keys))));

        return \count($keys);
    }

    /**
     * @return list<string> category ids, lower case hex
     */
    private static function ids(mixed $value): array
    {
        return array_values(array_map('strtolower', array_filter(\is_array($value) ? $value : [], static fn (mixed $id): bool => \is_string($id) && preg_match('/^[0-9a-f]{32}$/i', $id) === 1)));
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(\is_array($value) ? $value : [], 'is_string'));
    }
}
