<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopproof\Compliance\ProofLog\ProofLog;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The price history behind the 30-day lowest price (decision 0040): one row each time the price
 * of a product or variant changes, in the shop's default currency, gross and net, in the plugin's
 * own table. Written when products are saved (PriceHistorySubscriber) and by a daily sweep that
 * catches prices changed past the product API (imports, direct database edits). A variant without
 * its own price takes its parent's, as Shopware shows it. Once a day the new rows are sealed into
 * the proof log (type "prices"), so a later change to a recorded price shows. No personal data.
 */
class PriceHistory
{
    public const TABLE = 'shopproof_price_history';

    /** Id of the last history row sealed into the proof log. Not a plugin setting. */
    public const SEALED = 'ShopproofCompliance.priceHistorySealed';

    private const CHUNK = 500;

    /** @var array<string, list<array{at: string, gross: string, net: string}>> */
    private array $cache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ProofLog $log,
        private readonly SystemConfigService $config,
    ) {
    }

    /**
     * Records the current prices of these products (hex ids) and of their variants.
     *
     * @param list<string> $ids
     */
    public function recordProducts(array $ids, ?int $time = null): int
    {
        $ids = array_values(array_filter($ids, static fn (string $id): bool => Uuid::isValid($id)));
        if ($ids === []) {
            return 0;
        }
        $bytes = array_map(static fn (string $id): string => Uuid::fromHexToBytes($id), $ids);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(p.id)) AS id, p.price AS price, parent.price AS parent_price, p.child_count AS child_count
             FROM product p
             LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
             WHERE p.version_id = :version AND (p.id IN (:ids) OR p.parent_id IN (:ids))',
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'ids' => $bytes],
            ['ids' => ArrayParameterType::BINARY]
        );

        return $this->recordRows($rows, $time);
    }

    /**
     * Daily: every live product's current price against the last one recorded.
     */
    public function sweep(): int
    {
        $added = 0;
        $after = '';
        do {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT LOWER(HEX(p.id)) AS id, p.price AS price, parent.price AS parent_price, p.child_count AS child_count
                     FROM product p
                     LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
                     WHERE p.version_id = :version AND LOWER(HEX(p.id)) > :after
                     ORDER BY LOWER(HEX(p.id)) LIMIT %d',
                    self::CHUNK
                ),
                ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'after' => $after]
            );
            $added += $this->recordRows($rows, null);
            $last = end($rows);
            $after = \is_array($last) && \is_string($last['id'] ?? null) ? $last['id'] : $after;
        } while (\count($rows) === self::CHUNK);

        return $added;
    }

    /**
     * Daily: sweep, then seal the new rows into the proof log.
     */
    public function daily(): void
    {
        $this->sweep();
        $this->seal();
    }

    /**
     * Adds a row when the price differs from the last one recorded.
     */
    public function record(string $id, string $gross, string $net, ?int $time = null): bool
    {
        if (!Uuid::isValid($id) || !is_numeric($gross) || !is_numeric($net)) {
            return false;
        }
        $bytes = Uuid::fromHexToBytes($id);
        $last = $this->connection->fetchAssociative(
            \sprintf('SELECT gross, net FROM `%s` WHERE product_id = :id ORDER BY id DESC LIMIT 1', self::TABLE),
            ['id' => $bytes]
        );
        if (\is_array($last) && is_numeric($last['gross'] ?? null) && is_numeric($last['net'] ?? null) && (float) $last['gross'] === (float) $gross && (float) $last['net'] === (float) $net) {
            return false;
        }
        unset($this->cache[$id]);
        $this->connection->insert(self::TABLE, [
            'product_id' => $bytes,
            'recorded_at' => PriorPrice::iso($time ?? time()),
            'gross' => $gross,
            'net' => $net,
        ]);

        return true;
    }

    /**
     * A product's history, oldest first.
     *
     * @return list<array{at: string, gross: string, net: string}>
     */
    public function history(string $id): array
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        if (!Uuid::isValid($id)) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT recorded_at, gross, net FROM `%s` WHERE product_id = :id ORDER BY id', self::TABLE),
            ['id' => Uuid::fromHexToBytes($id)]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['at' => self::text($row, 'recorded_at'), 'gross' => self::text($row, 'gross'), 'net' => self::text($row, 'net')];
        }

        return $this->cache[$id] = $out;
    }

    /**
     * The prior price of a product, gross or net.
     *
     * @return array{status: string, price: string|null, since: string|null, start: string|null}
     */
    public function prior(string $id, bool $gross, string $createdAt): array
    {
        $field = $gross ? 'gross' : 'net';
        $rows = array_map(static fn (array $row): array => ['at' => $row['at'], 'price' => $row[$field]], $this->history($id));

        return PriorPrice::compute($rows, $createdAt);
    }

    /**
     * The newest recorded price of a product, or null.
     *
     * @return array{at: string, gross: string, net: string}|null
     */
    public function latest(string $id): ?array
    {
        $rows = $this->history($id);

        return $rows === [] ? null : $rows[\count($rows) - 1];
    }

    /**
     * SHA-256 over history rows in id order, one "id|product|time|gross|net" line each.
     *
     * @return array{hash: string, rows: int}
     */
    public function rowsHash(int $first, int $last): array
    {
        $ctx = hash_init('sha256');
        $count = 0;
        $from = $first;
        while ($from <= $last) {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf('SELECT id, LOWER(HEX(product_id)) AS product, recorded_at, gross, net FROM `%s` WHERE id >= :from AND id <= :last ORDER BY id LIMIT %d', self::TABLE, self::CHUNK),
                ['from' => $from, 'last' => $last]
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
                hash_update($ctx, $id . '|' . self::text($row, 'product') . '|' . self::text($row, 'recorded_at') . '|' . self::text($row, 'gross') . '|' . self::text($row, 'net') . "\n");
                ++$count;
                $from = $id + 1;
            }
        }

        return ['hash' => hash_final($ctx), 'rows' => $count];
    }

    /**
     * Seals the rows added since the last seal into the proof log. Nothing when there are none.
     */
    public function seal(): bool
    {
        $sealed = $this->config->get(self::SEALED);
        $sealed = is_numeric($sealed) ? (int) $sealed : 0;
        $max = $this->connection->fetchOne(\sprintf('SELECT MAX(id) FROM `%s`', self::TABLE));
        $last = is_numeric($max) ? (int) $max : 0;
        if ($last <= $sealed) {
            return false;
        }
        $digest = $this->rowsHash($sealed + 1, $last);
        $ok = $this->log->append('prices', ['first_id' => $sealed + 1, 'last_id' => $last, 'rows' => $digest['rows'], 'sha256' => $digest['hash']]);
        if ($ok) {
            $this->config->set(self::SEALED, $last);
        }

        return $ok;
    }

    /**
     * Checks every seal in the proof log against the history rows as they are now.
     *
     * @return array{seals: int, broken: list<array{first_id: int, last_id: int}>}
     */
    public function verifySeals(): array
    {
        $seals = 0;
        $broken = [];
        $after = 0;
        do {
            $rows = $this->log->rows($after);
            foreach ($rows as $row) {
                $after = $row['id'];
                if ($row['type'] !== 'prices') {
                    continue;
                }
                $summary = json_decode($row['summary'], true);
                if (!\is_array($summary) || !is_numeric($summary['first_id'] ?? null) || !is_numeric($summary['last_id'] ?? null) || !\is_string($summary['sha256'] ?? null)) {
                    continue;
                }
                ++$seals;
                $now = $this->rowsHash((int) $summary['first_id'], (int) $summary['last_id']);
                if (!hash_equals($summary['sha256'], $now['hash'])) {
                    $broken[] = ['first_id' => (int) $summary['first_id'], 'last_id' => (int) $summary['last_id']];
                }
            }
        } while (\count($rows) === 1000);

        return ['seals' => $seals, 'broken' => $broken];
    }

    /**
     * Products on sale (list price above the price, default currency) and what the shop shows for
     * each, for the setup check: those with no lowest price yet, and those whose crossed-out list
     * price is higher than the 30-day low. Up to 200 products on sale among the first 20,000, 20 names each;
     * capped says the check stopped before the end of the catalogue, so products after it were not checked.
     *
     * @return array{onSale: int, missing: list<array{id: string, name: string}>, higher: list<array{id: string, name: string}>, capped: bool}
     */
    public function saleCheck(): array
    {
        // The list price sits inside the price JSON, so the rows are read in chunks and checked here.
        $missing = [];
        $higher = [];
        $onSale = 0;
        $scanned = 0;
        $after = '';
        $capped = false;
        do {
            $rows = $this->connection->fetchAllAssociative(
                \sprintf(
                    'SELECT LOWER(HEX(p.id)) AS id, p.price AS price, parent.price AS parent_price, p.child_count AS child_count,
                            COALESCE(p.created_at, parent.created_at) AS created_at, COALESCE(t.name, pt.name) AS name
                     FROM product p
                     LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
                     LEFT JOIN product_translation t ON t.product_id = p.id AND t.product_version_id = p.version_id AND t.language_id = :lang
                     LEFT JOIN product_translation pt ON pt.product_id = parent.id AND pt.product_version_id = parent.version_id AND pt.language_id = :lang
                     WHERE p.version_id = :version AND COALESCE(p.active, parent.active) = 1 AND LOWER(HEX(p.id)) > :after
                     ORDER BY LOWER(HEX(p.id)) LIMIT %d',
                    self::CHUNK
                ),
                ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION), 'lang' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM), 'after' => $after]
            );
            $scanned += \count($rows);
            foreach ($rows as $row) {
                $id = self::text($row, 'id');
                $after = $id;
                if ((int) self::text($row, 'child_count') > 0) {
                    continue;
                }
                $price = PriorPrice::defaultPrice($row['price'] ?? null) ?? PriorPrice::defaultPrice($row['parent_price'] ?? null);
                if ($price === null || $price['list'] === null || (float) $price['list'] <= (float) $price['gross']) {
                    continue;
                }
                ++$onSale;
                $prior = $this->prior($id, true, self::createdIso(self::text($row, 'created_at')));
                $item = ['id' => $id, 'name' => self::text($row, 'name')];
                if ($prior['price'] === null) {
                    if (\count($missing) < 20) {
                        $missing[] = $item;
                    }
                } elseif ((float) $price['list'] > (float) $prior['price'] + 0.000001 && \count($higher) < 20) {
                    $higher[] = $item;
                }
                if ($onSale >= 200) {
                    // Rows after this one, in this chunk or the next, are not checked.
                    $capped = $id !== self::text(end($rows) ?: [], 'id') || \count($rows) === self::CHUNK;

                    break 2;
                }
            }
            // Stops after 20,000 products, so the setup check stays quick on a large catalogue.
            if (\count($rows) === self::CHUNK && $scanned >= 20000) {
                $capped = true;
            }
        } while (\count($rows) === self::CHUNK && $scanned < 20000);

        return ['onSale' => $onSale, 'missing' => $missing, 'higher' => $higher, 'capped' => $capped];
    }

    /**
     * A database time ("2026-11-10 09:00:00.000", UTC) as "2026-11-10T09:00:00Z", or ''.
     */
    public static function createdIso(string $value): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $value, $m) !== 1) {
            return '';
        }

        return $m[1] . 'T' . $m[2] . 'Z';
    }

    /**
     * When a product was created, as "2026-11-10T09:00:00Z", or ''.
     */
    public function createdAt(string $id): string
    {
        if (!Uuid::isValid($id)) {
            return '';
        }
        $value = $this->connection->fetchOne(
            'SELECT COALESCE(p.created_at, parent.created_at) FROM product p LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id WHERE p.id = :id AND p.version_id = :version',
            ['id' => Uuid::fromHexToBytes($id), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        return \is_string($value) ? self::createdIso($value) : '';
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function recordRows(array $rows, ?int $time): int
    {
        $added = 0;
        foreach ($rows as $row) {
            // A parent with variants only holds the variants' shared values; shoppers buy a variant.
            if ((int) self::text($row, 'child_count') > 0) {
                continue;
            }
            $price = PriorPrice::defaultPrice($row['price'] ?? null) ?? PriorPrice::defaultPrice($row['parent_price'] ?? null);
            if ($price !== null && $this->record(self::text($row, 'id'), $price['gross'], $price['net'], $time)) {
                ++$added;
            }
        }

        return $added;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }
}
