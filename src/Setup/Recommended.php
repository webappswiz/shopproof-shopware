<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * "Switch on what my shop needs", as the WooCommerce plugin's Overview card: one press switches on
 * every Shopproof feature the shop's own facts call for, at the place each feature already uses,
 * with one Undo for 14 days.
 *
 * The facts are read from the shop's database (physical and downloadable products, active
 * storefronts) and the shop-wide Shopproof settings, never asked. Nothing changes until the shop
 * owner presses the button: never on install, never in the background. Choices that belong to the
 * merchant (dispute statement, review checks, longer withdrawal periods, exclusions, small business
 * wording) are never made here. The VAT and shipping words under the price are Shopware's own and
 * the withdrawal button wording is automatic by default, so neither is offered.
 */
final class Recommended
{
    /** Where the values from before the last press are kept, for Undo. */
    public const UNDO = 'ShopproofCompliance.recommendedUndo';

    public const UNDO_DAYS = 14;

    /** Plan key => Shopproof setting, in the order the card lists them. */
    public const SWITCHES = [
        'guarantee' => 'showGuaranteeNotice',
        'priorPrice' => 'priorPriceLine',
        'deliveryLine' => 'deliveryLine',
        'digital' => 'digitalConsent',
    ];

    private const CONFIG = 'ShopproofCompliance.config.';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
    ) {
    }

    /**
     * What the shop already shows about itself. Read only.
     *
     * @return array{goods: bool, downloads: bool, storefront: bool, on: array<string, bool>}
     */
    public function facts(): array
    {
        $on = [];
        foreach (self::SWITCHES as $key => $field) {
            $on[$key] = $this->config->getBool(self::CONFIG . $field);
        }

        return [
            'goods' => $this->hasProduct(false),
            'downloads' => $this->hasProduct(true),
            'storefront' => $this->connection->fetchOne(
                'SELECT 1 FROM sales_channel WHERE type_id = :type AND active = 1 LIMIT 1',
                ['type' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)]
            ) !== false,
            'on' => $on,
        ];
    }

    /**
     * The switches the facts call for that are still off. Pure, so it is unit tested alone.
     *
     * @param array{goods?: bool, downloads?: bool, storefront?: bool, on?: array<string, bool>} $facts
     *
     * @return list<string>
     */
    public static function plan(array $facts): array
    {
        $on = $facts['on'] ?? [];
        $wanted = [
            'guarantee' => !empty($facts['goods']),
            'priorPrice' => !empty($facts['goods']),
            'deliveryLine' => !empty($facts['storefront']),
            'digital' => !empty($facts['downloads']),
        ];
        $plan = [];
        foreach ($wanted as $key => $need) {
            if ($need && empty($on[$key])) {
                $plan[] = $key;
            }
        }

        return $plan;
    }

    /**
     * Switches on the planned features for all sales channels and keeps the values they had, for
     * Undo. A sales channel that has its own value for a setting keeps it.
     *
     * @param list<string> $keys
     *
     * @return list<string> the keys that were switched on
     */
    public function apply(array $keys, int $now): array
    {
        $done = [];
        $before = [];
        foreach ($keys as $key) {
            $field = self::SWITCHES[$key] ?? null;
            if ($field === null || \in_array($key, $done, true)) {
                continue;
            }
            $before[$field] = $this->config->get(self::CONFIG . $field);
            $this->config->set(self::CONFIG . $field, true);
            $done[] = $key;
        }
        if ($done !== []) {
            $this->config->set(self::UNDO, ['time' => $now, 'keys' => $done, 'before' => $before]);
        }

        return $done;
    }

    /**
     * Puts back the values from before the last press. Only the settings that press changed are
     * touched; anything else the shop changed since stays as it is.
     */
    public function undo(?int $now = null): bool
    {
        $undo = $this->config->get(self::UNDO);
        // Undo is offered for UNDO_DAYS only; after that nothing is put back.
        if (self::read($undo, $now ?? time()) === null || !\is_array($undo) || !\is_array($undo['before'] ?? null)) {
            return false;
        }
        $fields = array_values(self::SWITCHES);
        foreach ($undo['before'] as $field => $value) {
            if (\in_array($field, $fields, true)) {
                // null removes the value, so the setting is back to its default.
                $this->config->set(self::CONFIG . $field, \is_bool($value) ? $value : null);
            }
        }
        $this->config->set(self::UNDO, null);

        return true;
    }

    /**
     * The last press, while it can be undone.
     *
     * @return array{time: int, keys: list<string>}|null
     */
    public function last(int $now): ?array
    {
        return self::read($this->config->get(self::UNDO), $now);
    }

    /**
     * @return array{time: int, keys: list<string>}|null
     */
    public static function read(mixed $stored, int $now): ?array
    {
        if (!\is_array($stored) || !\is_array($stored['keys'] ?? null) || !\is_int($stored['time'] ?? null)) {
            return null;
        }
        $keys = array_values(array_filter($stored['keys'], static fn (mixed $key): bool => \is_string($key) && isset(self::SWITCHES[$key])));
        if ($keys === [] || $stored['time'] < $now - self::UNDO_DAYS * 86400) {
            return null;
        }

        return ['time' => $stored['time'], 'keys' => $keys];
    }

    /**
     * Whether an active product is a download (Shopware's digital type, or a file to download), or,
     * with $download false, one that is neither.
     */
    private function hasProduct(bool $download): bool
    {
        $digital = 'COALESCE(p.type, parent.type, \'\') = \'digital\' OR EXISTS (SELECT 1 FROM product_download d WHERE d.product_id = p.id AND d.product_version_id = p.version_id)';

        return $this->connection->fetchOne(
            'SELECT 1 FROM product p
             LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
             WHERE p.version_id = :version AND COALESCE(p.active, parent.active) = 1 AND COALESCE(p.child_count, 0) = 0
               AND ' . ($download ? '(' . $digital . ')' : 'NOT (' . $digital . ')') . '
             LIMIT 1',
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        ) !== false;
    }
}
