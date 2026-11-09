<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The shop's own record of withdrawal requests, in the plugin's table on the shop's server.
 */
class WithdrawalStore
{
    public const TABLE = 'shopproof_withdrawal_request';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{first_name: string, last_name: string, email: string, contract_number: string, comment: string, submitted_at: \DateTimeImmutable} $record
     *
     * @return string the new record's id (hex)
     */
    public function add(array $record, ?string $salesChannelId): string
    {
        $id = Uuid::randomHex();
        $utc = new \DateTimeZone('UTC');
        $this->connection->insert(self::TABLE, [
            'id' => Uuid::fromHexToBytes($id),
            'sales_channel_id' => $salesChannelId !== null && Uuid::isValid($salesChannelId) ? Uuid::fromHexToBytes($salesChannelId) : null,
            'first_name' => $record['first_name'],
            'last_name' => $record['last_name'],
            'email' => $record['email'],
            'contract_number' => $record['contract_number'],
            'comment' => $record['comment'] !== '' ? $record['comment'] : null,
            'order_id' => $this->matchOrder($record['contract_number'], $record['email'], $salesChannelId),
            'submitted_at' => $record['submitted_at']->setTimezone($utc)->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'created_at' => (new \DateTimeImmutable('now', $utc))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $id;
    }

    /**
     * The order the request names, when its number (as typed, or without "#", spaces or a word
     * like "Order" in front, see WithdrawalRecord::orderNumbers) exists in the same sales channel
     * and the order's customer email matches. Otherwise null; the record is kept either way.
     */
    private function matchOrder(string $orderNumber, string $email, ?string $salesChannelId): ?string
    {
        $numbers = WithdrawalRecord::orderNumbers($orderNumber);
        if ($numbers === []) {
            return null;
        }
        $sql = 'SELECT o.id, oc.email FROM `order` o
            INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
            WHERE o.order_number IN (:numbers) AND o.version_id = :version';
        $params = ['numbers' => $numbers, 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)];
        $types = ['numbers' => ArrayParameterType::STRING];
        if ($salesChannelId !== null && Uuid::isValid($salesChannelId)) {
            $sql .= ' AND o.sales_channel_id = :channel';
            $params['channel'] = Uuid::fromHexToBytes($salesChannelId);
        }
        foreach ($this->connection->fetchAllAssociative($sql . ' LIMIT 5', $params, $types) as $row) {
            if (\is_string($row['id'] ?? null) && WithdrawalRecord::sameEmail(self::str($row['email'] ?? null), $email)) {
                return $row['id'];
            }
        }

        return null;
    }

    /**
     * Newest first, optionally filtered by email, name or contract number.
     *
     * @return array{total: int, items: list<array<string, string|null>>}
     */
    public function list(int $page, int $limit, string $term = ''): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $where = '';
        $params = [];
        if (trim($term) !== '') {
            $where = ' WHERE (w.email LIKE :term OR w.contract_number LIKE :term OR w.first_name LIKE :term OR w.last_name LIKE :term)';
            $params['term'] = '%' . addcslashes(trim($term), '%_\\') . '%';
        }
        $total = self::int($this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' w' . $where, $params));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(w.id)) AS id, w.first_name, w.last_name, w.email, w.contract_number, w.comment,
                    LOWER(HEX(w.order_id)) AS order_id, o.order_number, sct.name AS sales_channel, w.submitted_at
             FROM ' . self::TABLE . ' w
             LEFT JOIN `order` o ON o.id = w.order_id AND o.version_id = :version
             LEFT JOIN sales_channel sc ON sc.id = w.sales_channel_id
             LEFT JOIN sales_channel_translation sct ON sct.sales_channel_id = sc.id AND sct.language_id = sc.language_id'
            . $where . ' ORDER BY w.submitted_at DESC LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit),
            $params + ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => self::str($row['id'] ?? null),
                'firstName' => self::str($row['first_name'] ?? null),
                'lastName' => self::str($row['last_name'] ?? null),
                'email' => self::str($row['email'] ?? null),
                'contractNumber' => self::str($row['contract_number'] ?? null),
                'comment' => self::str($row['comment'] ?? null),
                'orderId' => self::str($row['order_id'] ?? null) !== '' ? self::str($row['order_id']) : null,
                'orderNumber' => self::str($row['order_number'] ?? null) !== '' ? self::str($row['order_number']) : null,
                'salesChannel' => self::str($row['sales_channel'] ?? null),
                'submittedAt' => self::atom(self::str($row['submitted_at'] ?? null)),
            ];
        }

        return ['total' => $total, 'items' => $items];
    }

    /**
     * @param list<string> $ids hex ids
     *
     * @return int how many records were deleted
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
