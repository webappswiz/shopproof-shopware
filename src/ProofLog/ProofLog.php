<?php declare(strict_types=1);

namespace Shopproof\Compliance\ProofLog;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The shop's append-only proof log (decision 0013) in the plugin's own table on the shop's
 * server: daily snapshots of what the shop shows, each withdrawal (no personal details) and
 * changes to how long the log is kept. Nothing leaves the shop.
 *
 * Two hashes kept in the shop's settings anchor the chain at both ends: the newest entry's hash
 * (HEAD, written with every entry) and the hash of the last entry the retention removed (PRUNED).
 * So the chain check also finds removed newest or oldest entries and an emptied table, not only
 * changed entries. Someone who can edit the database can still rewrite all of it; the log shows
 * changes, it cannot prevent them.
 */
class ProofLog
{
    public const TABLE = 'shopproof_proof_log';

    public const TYPES = ['snapshot', 'withdrawal', 'retention'];

    public const DEFAULT_RETENTION = 730;

    /** Hash of the newest entry, written with every entry. Not a plugin setting, so not in the settings form. */
    public const HEAD = 'ShopproofCompliance.proofLogHead';

    /** Hash of the newest entry the retention removed, which the oldest remaining entry follows. */
    public const PRUNED = 'ShopproofCompliance.proofLogPruned';

    private const LOCK = 'shopproof_proof_log';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
    ) {
    }

    /**
     * Days a retention setting keeps entries: 0 keeps everything, otherwise at least 30; empty or
     * unreadable means the default.
     */
    public static function retentionFrom(mixed $value): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return self::DEFAULT_RETENTION;
        }
        $days = (int) $value;

        return $days <= 0 ? 0 : max(30, $days);
    }

    /**
     * Adds an entry after the newest one. A named lock keeps two requests from both building on
     * the same previous hash.
     *
     * @param array<mixed> $summary
     */
    public function append(string $type, array $summary): bool
    {
        if (!\in_array($type, self::TYPES, true)) {
            return false;
        }
        $locked = $this->connection->fetchOne('SELECT GET_LOCK(:lock, 10)', ['lock' => self::LOCK]);
        if (!is_numeric($locked) || (int) $locked !== 1) {
            return false;
        }
        try {
            $last = $this->connection->fetchOne(\sprintf('SELECT hash FROM `%s` ORDER BY id DESC LIMIT 1', self::TABLE));
            // After the retention removed every entry, the chain goes on from the last one written.
            $prev = \is_string($last) && \strlen($last) === 64 ? $last : ($this->anchor(self::HEAD) ?? ProofChain::GENESIS);
            $createdAt = gmdate('Y-m-d\TH:i:s\Z');
            $json = ProofChain::encode($summary);
            $hash = ProofChain::entryHash($prev, $createdAt, $type, $json);
            $this->connection->insert(self::TABLE, [
                'created_at' => $createdAt,
                'type' => $type,
                'summary' => $json,
                'prev_hash' => $prev,
                'hash' => $hash,
            ]);
            $this->config->set(self::HEAD, $hash);
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(:lock)', ['lock' => self::LOCK]);
        }

        return true;
    }

    /**
     * Entries after an id, oldest first.
     *
     * @return list<array{id: int, created_at: string, type: string, summary: string, prev_hash: string, hash: string}>
     */
    public function rows(int $afterId = 0, int $limit = 1000): array
    {
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT id, created_at, type, summary, prev_hash, hash FROM `%s` WHERE id > :after ORDER BY id LIMIT %d', self::TABLE, max(1, min(1000, $limit))),
            ['after' => $afterId]
        );

        return array_map(self::row(...), $rows);
    }

    /**
     * Newest first, one page, for the Shopproof page.
     *
     * @return array{total: int, items: list<array{id: int, created_at: string, type: string, summary: string, prev_hash: string, hash: string}>}
     */
    public function page(int $page, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $offset = (max(1, $page) - 1) * $limit;
        $rows = $this->connection->fetchAllAssociative(
            \sprintf('SELECT id, created_at, type, summary, prev_hash, hash FROM `%s` ORDER BY id DESC LIMIT %d OFFSET %d', self::TABLE, $limit, $offset)
        );
        $total = $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM `%s`', self::TABLE));

        return ['total' => is_numeric($total) ? (int) $total : 0, 'items' => array_map(self::row(...), $rows)];
    }

    /**
     * Checks the whole chain in pages of 1000: every entry's hash, the oldest entry against the
     * last one the retention removed (or the start of the chain), and the newest against HEAD.
     *
     * @return array{ok: bool, checked: int, brokenAt: int, last: string|null}
     */
    public function verifyAll(): array
    {
        $after = 0;
        $total = 0;
        $prev = $this->anchor(self::PRUNED) ?? ProofChain::GENESIS;
        $last = null;
        $lastId = 0;
        do {
            $rows = $this->rows($after);
            $result = ProofChain::verify($rows, $prev);
            $total += $result['checked'];
            if (!$result['ok']) {
                $result['checked'] = $total;

                return $result;
            }
            if ($rows !== []) {
                $prev = $last = $result['last'];
                $after = $lastId = $rows[\count($rows) - 1]['id'];
            }
        } while ($rows !== []);

        if (!ProofChain::anchored($last, $this->anchor(self::HEAD), $this->anchor(self::PRUNED))) {
            // The newest entries are missing (or the table was emptied): broken after the last one left.
            return ['ok' => false, 'checked' => $total, 'brokenAt' => $lastId + 1, 'last' => $last];
        }

        return ['ok' => true, 'checked' => $total, 'brokenAt' => 0, 'last' => $last];
    }

    /**
     * Removes entries older than the retention period (0 keeps everything): every entry up to the
     * newest one older than the period, so the chain keeps no gap, and stores that entry's hash
     * for the chain check.
     */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $locked = $this->connection->fetchOne('SELECT GET_LOCK(:lock, 10)', ['lock' => self::LOCK]);
        if (!is_numeric($locked) || (int) $locked !== 1) {
            return 0;
        }
        try {
            $cut = $this->connection->fetchAssociative(
                \sprintf('SELECT id, hash FROM `%s` WHERE created_at < :cutoff ORDER BY id DESC LIMIT 1', self::TABLE),
                ['cutoff' => gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400)]
            );
            if ($cut === false || !is_numeric($cut['id'] ?? null) || !\is_string($cut['hash'] ?? null)) {
                return 0;
            }
            $removed = (int) $this->connection->executeStatement(
                \sprintf('DELETE FROM `%s` WHERE id <= :id', self::TABLE),
                ['id' => (int) $cut['id']]
            );
            $this->config->set(self::PRUNED, $cut['hash']);

            return $removed;
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(:lock)', ['lock' => self::LOCK]);
        }
    }

    /**
     * The newest entry's summary of a type, decoded, or null when there is none.
     *
     * @return array<mixed>|null
     */
    public function lastSummary(string $type): ?array
    {
        $json = $this->connection->fetchOne(
            \sprintf('SELECT summary FROM `%s` WHERE type = :type ORDER BY id DESC LIMIT 1', self::TABLE),
            ['type' => $type]
        );
        $decoded = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($decoded) ? $decoded : null;
    }

    public function lastTime(string $type): string
    {
        $time = $this->connection->fetchOne(
            \sprintf('SELECT created_at FROM `%s` WHERE type = :type ORDER BY id DESC LIMIT 1', self::TABLE),
            ['type' => $type]
        );

        return \is_string($time) ? $time : '';
    }

    public function exists(): bool
    {
        return $this->connection->fetchOne('SHOW TABLES LIKE :table', ['table' => self::TABLE]) === self::TABLE;
    }

    private function anchor(string $key): ?string
    {
        $value = $this->config->get($key);

        return \is_string($value) && \strlen($value) === 64 ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, created_at: string, type: string, summary: string, prev_hash: string, hash: string}
     */
    private static function row(array $row): array
    {
        $text = static fn (string $key): string => \is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';

        return [
            'id' => (int) $text('id'),
            'created_at' => $text('created_at'),
            'type' => $text('type'),
            'summary' => $text('summary'),
            'prev_hash' => $text('prev_hash'),
            'hash' => $text('hash'),
        ];
    }
}
