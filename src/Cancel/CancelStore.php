<?php declare(strict_types=1);

namespace Shopproof\Compliance\Cancel;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The cancellations sent on the cancel page, in the plugin's own table. Removed on uninstall
 * unless the merchant keeps the data.
 */
final class CancelStore
{
    public const TABLE = 'shopproof_cancellation';

    /** Columns and record keys of the customer's details. */
    private const DETAILS = [
        'name' => 'name',
        'email' => 'email',
        'contract' => 'contract',
        'cancel_type' => 'cancelType',
        'reason' => 'reason',
        'end_mode' => 'endMode',
        'end_date' => 'endDate',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Keeps a cancellation and returns its id (hex).
     *
     * @param array{name: string, email: string, contract: string, cancelType: string, reason: string, endMode: string, endDate: string} $data
     */
    public function add(array $data, ?string $salesChannelId, string $reference, string $lang, string $fingerprint, \DateTimeImmutable $at): string
    {
        $id = Uuid::randomHex();
        $now = $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        $this->connection->insert(self::TABLE, [
            'id' => Uuid::fromHexToBytes($id),
            'sales_channel_id' => $salesChannelId !== null && Uuid::isValid($salesChannelId) ? Uuid::fromHexToBytes($salesChannelId) : null,
            'reference' => $reference,
            'name' => $data['name'],
            'email' => $data['email'],
            'contract' => $data['contract'],
            'cancel_type' => $data['cancelType'],
            'reason' => $data['reason'] !== '' ? $data['reason'] : null,
            'end_mode' => $data['endMode'],
            'end_date' => $data['endDate'] !== '' ? $data['endDate'] : null,
            'lang' => $lang,
            'fingerprint' => $fingerprint,
            'received_at' => $now,
            'created_at' => $now,
        ]);

        return $id;
    }

    public function setSent(string $id, bool $receipt, bool $shopCopy): void
    {
        $this->connection->update(self::TABLE, ['receipt_sent' => $receipt ? 1 : 0, 'shop_copy_sent' => $shopCopy ? 1 : 0], ['id' => Uuid::fromHexToBytes($id)]);
    }

    /**
     * The same cancellation sent again within some minutes (a page reload), or null.
     */
    public function recent(string $fingerprint, int $minutes = 10): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM ' . self::TABLE . ' WHERE fingerprint = :print AND received_at >= :since ORDER BY received_at ASC LIMIT 1',
            ['print' => $fingerprint, 'since' => (new \DateTimeImmutable('-' . $minutes . ' minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v')]
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return array<string, string>|null
     */
    public function find(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative($this->select() . ' WHERE c.id = :id', ['id' => Uuid::fromHexToBytes($id)]);

        return $row === false ? null : self::record($row);
    }

    /**
     * Newest first, optionally filtered by email, name, contract or reference.
     *
     * @return array{total: int, open: int, items: list<array<string, string>>}
     */
    public function list(int $page, int $limit, string $term = ''): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $where = '';
        $params = [];
        if (trim($term) !== '') {
            $where = ' WHERE (c.email LIKE :term OR c.name LIKE :term OR c.contract LIKE :term OR c.reference LIKE :term)';
            $params['term'] = '%' . addcslashes(trim($term), '%_\\') . '%';
        }
        $total = self::int($this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' c' . $where, $params));
        $open = self::int($this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE done_at IS NULL'));
        $rows = $this->connection->fetchAllAssociative(
            $this->select() . $where . ' ORDER BY c.received_at DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            $params
        );

        return ['total' => $total, 'open' => $open, 'items' => array_map(self::record(...), $rows)];
    }

    /**
     * Marks a cancellation done. False when it is missing or already done.
     */
    public function markDone(string $id, \DateTimeImmutable $at): bool
    {
        if (!Uuid::isValid($id)) {
            return false;
        }

        return $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET done_at = :at WHERE id = :id AND done_at IS NULL',
            ['at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'), 'id' => Uuid::fromHexToBytes($id)]
        ) > 0;
    }

    /**
     * @param list<string> $ids hex ids
     */
    public function delete(array $ids): int
    {
        $ids = array_values(array_filter($ids, static fn (string $id): bool => Uuid::isValid($id)));
        if ($ids === []) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE id IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($ids)],
            ['ids' => ArrayParameterType::BINARY]
        );
    }

    public function count(): int
    {
        return self::int($this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE));
    }

    private function select(): string
    {
        return 'SELECT LOWER(HEX(c.id)) AS id, LOWER(HEX(c.sales_channel_id)) AS sales_channel_id, sct.name AS sales_channel, c.reference, '
            . implode(', ', array_map(static fn (string $column): string => 'c.' . $column, array_keys(self::DETAILS)))
            . ', c.lang, c.receipt_sent, c.shop_copy_sent, c.received_at, c.done_at
             FROM ' . self::TABLE . ' c
             LEFT JOIN sales_channel sc ON sc.id = c.sales_channel_id
             LEFT JOIN sales_channel_translation sct ON sct.sales_channel_id = sc.id AND sct.language_id = sc.language_id';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private static function record(array $row): array
    {
        $out = [
            'id' => self::str($row['id'] ?? null),
            'salesChannelId' => self::str($row['sales_channel_id'] ?? null),
            'salesChannel' => self::str($row['sales_channel'] ?? null),
            'reference' => self::str($row['reference'] ?? null),
        ];
        foreach (self::DETAILS as $column => $key) {
            $out[$key] = self::str($row[$column] ?? null);
        }
        $out['lang'] = self::str($row['lang'] ?? null);
        $out['receiptSent'] = self::int($row['receipt_sent'] ?? null) === 1 ? '1' : '0';
        $out['shopCopySent'] = self::int($row['shop_copy_sent'] ?? null) === 1 ? '1' : '0';
        $out['receivedAt'] = self::atom(self::str($row['received_at'] ?? null));
        $out['doneAt'] = self::atom(self::str($row['done_at'] ?? null));

        return $out;
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : (\is_string($value) && is_numeric($value) ? (int) $value : 0);
    }

    private static function atom(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        $time = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $stored, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stored, new \DateTimeZone('UTC'));

        return $time !== false ? $time->format(\DATE_ATOM) : $stored;
    }
}
