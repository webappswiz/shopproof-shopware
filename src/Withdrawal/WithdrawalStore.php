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

    /** Where a withdrawal stands, in order. */
    public const STATES = ['received', 'return_arrived', 'refund_sent'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{first_name: string, last_name: string, email: string, contract_number: string, comment: string, items?: string, order_date?: string, submitted_at: \DateTimeImmutable} $record
     * @param int|null $periodDays the shop's withdrawal period when the request arrived, kept so a later change does not move its deadline
     *
     * @return string the new record's id (hex)
     */
    public function add(array $record, ?string $salesChannelId, ?int $periodDays = null): string
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
            'items' => ($record['items'] ?? '') !== '' ? $record['items'] : null,
            'order_date' => ($record['order_date'] ?? '') !== '' ? $record['order_date'] : null,
            'order_id' => $this->matchOrder($record['contract_number'], $record['email'], $salesChannelId),
            'status' => self::STATES[0],
            'period_days' => $periodDays,
            'submitted_at' => $record['submitted_at']->setTimezone($utc)->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'created_at' => (new \DateTimeImmutable('now', $utc))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        return $id;
    }

    /**
     * The id (hex) of the order a number and email name in a sales channel, '' when none fits.
     */
    public function orderFor(string $orderNumber, string $email, ?string $salesChannelId): string
    {
        $id = $this->matchOrder($orderNumber, $email, $salesChannelId);

        return $id !== null ? Uuid::fromBytesToHex($id) : '';
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
     * Newest first, optionally filtered by email, name or contract number. Each item has its
     * deadline clock, with days in the given time zone (the admin user's).
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function list(int $page, int $limit, string $term = '', ?\DateTimeZone $zone = null, int $defaultDays = WithdrawalClock::DAYS): array
    {
        $zone ??= new \DateTimeZone('UTC');
        $today = (new \DateTimeImmutable('now', $zone))->format('Y-m-d');
        $none = ['shippedAt' => '', 'open' => false, 'refundedAt' => '', 'partlyRefundedAt' => '', 'paymentState' => ''];
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
            'SELECT LOWER(HEX(w.id)) AS id, w.first_name, w.last_name, w.email, w.contract_number, w.comment, w.items, w.order_date,
                    LOWER(HEX(w.order_id)) AS order_id, o.order_number, sct.name AS sales_channel, w.submitted_at,
                    w.status, w.return_arrived_at, w.refund_sent_at, w.delivered_on, w.period_days,
                    w.webhook_status, w.webhook_attempts, w.webhook_code, w.webhook_at
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
                'items' => self::str($row['items'] ?? null),
                'orderDate' => self::str($row['order_date'] ?? null) !== '' ? substr(self::str($row['order_date']), 0, 10) : null,
                'orderId' => self::str($row['order_id'] ?? null) !== '' ? self::str($row['order_id']) : null,
                'orderNumber' => self::str($row['order_number'] ?? null) !== '' ? self::str($row['order_number']) : null,
                'salesChannel' => self::str($row['sales_channel'] ?? null),
                'submittedAt' => self::atom(self::str($row['submitted_at'] ?? null)),
                'status' => \in_array($row['status'] ?? null, self::STATES, true) ? self::str($row['status']) : self::STATES[0],
                'returnArrivedAt' => self::atom(self::str($row['return_arrived_at'] ?? null)),
                'refundSentAt' => self::atom(self::str($row['refund_sent_at'] ?? null)),
                'deliveredOn' => WithdrawalClock::cleanDate(substr(self::str($row['delivered_on'] ?? null), 0, 10)),
                'periodDays' => self::int($row['period_days'] ?? null),
                'webhook' => \is_string($row['webhook_status'] ?? null) ? [
                    'status' => self::str($row['webhook_status']),
                    'attempts' => self::int($row['webhook_attempts'] ?? null),
                    'code' => self::int($row['webhook_code'] ?? null),
                    'at' => self::atom(self::str($row['webhook_at'] ?? null)),
                ] : null,
            ];
        }
        foreach ($items as $n => $item) {
            $order = \is_string($item['orderId']) ? $this->orderFacts($item['orderId']) : $none;
            $items[$n]['clock'] = WithdrawalClock::forRecord($item, $order, $zone, $today, $defaultDays);
        }

        return ['total' => $total, 'items' => $items];
    }

    /**
     * One record with what the status emails and page need, or null.
     *
     * @return array{id: string, salesChannelId: string, languageId: string, firstName: string, lastName: string, email: string, contractNumber: string, orderId: string, submittedAt: string, status: string, returnArrivedAt: string, refundSentAt: string, items: string}|null
     */
    public function find(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(w.id)) AS id, LOWER(HEX(w.sales_channel_id)) AS sales_channel_id, LOWER(HEX(COALESCE(o.language_id, sc.language_id))) AS language_id,
                    w.first_name, w.last_name, w.email, w.contract_number, LOWER(HEX(w.order_id)) AS order_id, w.submitted_at,
                    w.status, w.return_arrived_at, w.refund_sent_at, w.items
             FROM ' . self::TABLE . ' w
             LEFT JOIN `order` o ON o.id = w.order_id AND o.version_id = :version
             LEFT JOIN sales_channel sc ON sc.id = w.sales_channel_id
             WHERE w.id = :id',
            ['id' => Uuid::fromHexToBytes($id), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        if ($row === false) {
            return null;
        }

        return [
            'id' => self::str($row['id'] ?? null),
            'salesChannelId' => self::str($row['sales_channel_id'] ?? null),
            'languageId' => self::str($row['language_id'] ?? null),
            'firstName' => self::str($row['first_name'] ?? null),
            'lastName' => self::str($row['last_name'] ?? null),
            'email' => self::str($row['email'] ?? null),
            'contractNumber' => self::str($row['contract_number'] ?? null),
            'orderId' => self::str($row['order_id'] ?? null),
            'submittedAt' => self::atom(self::str($row['submitted_at'] ?? null)),
            'status' => \in_array($row['status'] ?? null, self::STATES, true) ? self::str($row['status']) : self::STATES[0],
            'returnArrivedAt' => self::atom(self::str($row['return_arrived_at'] ?? null)),
            'refundSentAt' => self::atom(self::str($row['refund_sent_at'] ?? null)),
            'items' => self::str($row['items'] ?? null),
        ];
    }

    /**
     * Moves a record to a state, with the time of each step reached. Going back clears the times
     * of the later steps. False when the record is missing or already in that state.
     */
    public function setStatus(string $id, string $state): bool
    {
        $index = array_search($state, self::STATES, true);
        if ($index === false || !Uuid::isValid($id)) {
            return false;
        }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        [$return, $refund] = match ($index) {
            0 => ['NULL', 'NULL'],
            1 => [':now', 'NULL'],
            default => ['return_arrived_at', ':now'],
        };

        return (int) $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET status = :state, return_arrived_at = ' . $return . ', refund_sent_at = ' . $refund . '
             WHERE id = :id AND status <> :state',
            ['state' => $state, 'now' => $now, 'id' => Uuid::fromHexToBytes($id)]
        ) > 0;
    }

    /**
     * The day the goods arrived, as the merchant entered it ('' clears it).
     */
    public function setDelivered(string $id, string $day): bool
    {
        if (!Uuid::isValid($id)) {
            return false;
        }
        $day = WithdrawalClock::cleanDate($day);
        $this->connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET delivered_on = :day WHERE id = :id',
            ['day' => $day !== '' ? $day : null, 'id' => Uuid::fromHexToBytes($id)]
        );

        return $this->find($id) !== null;
    }

    /**
     * What the matched order says for the clock, as UTC times: when its delivery was last marked
     * shipped, whether the delivery is still open, when its payment was last marked refunded or
     * partly refunded, and its current payment state.
     *
     * @return array{shippedAt: string, open: bool, refundedAt: string, partlyRefundedAt: string, paymentState: string}
     */
    public function orderFacts(string $orderId): array
    {
        $facts = ['shippedAt' => '', 'open' => false, 'refundedAt' => '', 'partlyRefundedAt' => '', 'paymentState' => ''];
        if (!Uuid::isValid($orderId)) {
            return $facts;
        }
        $params = ['order' => Uuid::fromHexToBytes($orderId), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)];
        $delivery = $this->connection->fetchAssociative(
            'SELECT d.id, s.technical_name AS state FROM order_delivery d INNER JOIN state_machine_state s ON s.id = d.state_id
             WHERE d.order_id = :order AND d.order_version_id = :version AND d.version_id = :version ORDER BY d.created_at LIMIT 1',
            $params
        );
        if ($delivery !== false && \is_string($delivery['id'] ?? null)) {
            $facts['open'] = ($delivery['state'] ?? null) === 'open';
            $facts['shippedAt'] = self::atom(self::str($this->connection->fetchOne(
                'SELECT MAX(h.created_at) FROM state_machine_history h INNER JOIN state_machine_state s ON s.id = h.to_state_id
                 WHERE h.entity_name = :entity AND h.referenced_id = :id AND s.technical_name = :state',
                ['entity' => 'order_delivery', 'id' => $delivery['id'], 'state' => 'shipped']
            )));
        }
        $payment = $this->connection->fetchAssociative(
            'SELECT t.id, s.technical_name AS state FROM order_transaction t INNER JOIN state_machine_state s ON s.id = t.state_id
             WHERE t.order_id = :order AND t.order_version_id = :version AND t.version_id = :version ORDER BY t.created_at DESC LIMIT 1',
            $params
        );
        if ($payment !== false && \is_string($payment['id'] ?? null)) {
            $facts['paymentState'] = self::str($payment['state'] ?? null);
            foreach (['refunded' => 'refundedAt', 'refunded_partially' => 'partlyRefundedAt'] as $state => $key) {
                $facts[$key] = self::atom(self::str($this->connection->fetchOne(
                    'SELECT MAX(h.created_at) FROM state_machine_history h INNER JOIN state_machine_state s ON s.id = h.to_state_id
                     WHERE h.entity_name = :entity AND h.referenced_id = :id AND s.technical_name = :state',
                    ['entity' => 'order_transaction', 'id' => $payment['id'], 'state' => $state]
                )));
            }
        }

        return $facts;
    }

    /**
     * The newest record from an email address in a sales channel received in the last minutes, so
     * Shopware's receipt email (sent right after the record is kept) can link to its status page.
     */
    public function latestFor(string $salesChannelId, string $email, int $minutes = 10): ?string
    {
        if (!Uuid::isValid($salesChannelId) || trim($email) === '') {
            return null;
        }
        $since = (new \DateTimeImmutable('-' . max(1, $minutes) . ' minutes', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM ' . self::TABLE . ' WHERE sales_channel_id = :channel AND LOWER(email) = :email AND created_at >= :since ORDER BY created_at DESC LIMIT 1',
            ['channel' => Uuid::fromHexToBytes($salesChannelId), 'email' => mb_strtolower(trim($email)), 'since' => $since]
        );

        return \is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Records matched to an order that are not marked as refunded yet.
     *
     * @return list<string> hex ids
     */
    public function openForOrder(string $orderId): array
    {
        if (!Uuid::isValid($orderId)) {
            return [];
        }

        return array_map(
            static fn (mixed $id): string => \is_string($id) ? $id : '',
            $this->connection->fetchFirstColumn(
                'SELECT LOWER(HEX(id)) FROM ' . self::TABLE . ' WHERE order_id = :order AND status <> :refunded LIMIT 20',
                ['order' => Uuid::fromHexToBytes($orderId), 'refunded' => self::STATES[2]]
            )
        );
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

    /**
     * What the proof log keeps about a record: the stored row (hashed there, never copied), its
     * time and the billing country of the matched order ('' when no order matched).
     *
     * @return array{record: array<string, mixed>, receivedAt: string, country: string}|null
     */
    public function proofFacts(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(w.id)) AS id, LOWER(HEX(w.sales_channel_id)) AS sales_channel_id, w.first_name, w.last_name, w.email,
                    w.contract_number, w.comment, LOWER(HEX(w.order_id)) AS order_id, w.submitted_at, w.created_at,
                    (SELECT c.iso FROM `order` o
                        INNER JOIN order_address a ON a.id = o.billing_address_id AND a.version_id = o.version_id
                        INNER JOIN country c ON c.id = a.country_id
                      WHERE o.id = w.order_id AND o.version_id = :version LIMIT 1) AS country
             FROM ' . self::TABLE . ' w WHERE w.id = :id',
            ['id' => Uuid::fromHexToBytes($id), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        if ($row === false) {
            return null;
        }
        $country = self::str($row['country'] ?? null);
        unset($row['country']);

        return ['record' => $row, 'receivedAt' => self::atom(self::str($row['submitted_at'] ?? null)), 'country' => $country];
    }

    /**
     * What the returns tool gets about a record, or null.
     *
     * @return array{id: string, salesChannelId: string, languageId: string, locale: string, firstName: string, lastName: string, email: string, contractNumber: string, orderNumber: string, orderId: string, orderDate: string, comment: string, items: string, submittedAt: string, country: string}|null
     */
    public function webhookFacts(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(w.id)) AS id, LOWER(HEX(w.sales_channel_id)) AS sales_channel_id, LOWER(HEX(COALESCE(o.language_id, sc.language_id))) AS language_id,
                    lo.code AS locale, w.first_name, w.last_name, w.email, w.contract_number, o.order_number, LOWER(HEX(w.order_id)) AS order_id,
                    COALESCE(w.order_date, DATE(o.order_date_time)) AS order_date, w.comment, w.items, w.submitted_at, c.iso AS country
             FROM ' . self::TABLE . ' w
             LEFT JOIN `order` o ON o.id = w.order_id AND o.version_id = :version
             LEFT JOIN order_address a ON a.id = o.billing_address_id AND a.version_id = o.version_id
             LEFT JOIN country c ON c.id = a.country_id
             LEFT JOIN sales_channel sc ON sc.id = w.sales_channel_id
             LEFT JOIN language la ON la.id = COALESCE(o.language_id, sc.language_id)
             LEFT JOIN locale lo ON lo.id = la.locale_id
             WHERE w.id = :id',
            ['id' => Uuid::fromHexToBytes($id), 'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
        if ($row === false) {
            return null;
        }

        return [
            'id' => self::str($row['id'] ?? null),
            'salesChannelId' => self::str($row['sales_channel_id'] ?? null),
            'languageId' => self::str($row['language_id'] ?? null),
            'locale' => self::str($row['locale'] ?? null),
            'firstName' => self::str($row['first_name'] ?? null),
            'lastName' => self::str($row['last_name'] ?? null),
            'email' => self::str($row['email'] ?? null),
            'contractNumber' => self::str($row['contract_number'] ?? null),
            'orderNumber' => self::str($row['order_number'] ?? null),
            'orderId' => self::str($row['order_id'] ?? null),
            'orderDate' => WithdrawalClock::cleanDate(substr(self::str($row['order_date'] ?? null), 0, 10)),
            'comment' => self::str($row['comment'] ?? null),
            'items' => self::str($row['items'] ?? null),
            'submittedAt' => self::atom(self::str($row['submitted_at'] ?? null)),
            'country' => self::str($row['country'] ?? null),
        ];
    }

    /**
     * Notes how sending the record to the returns tool went.
     */
    public function setWebhook(string $id, string $status, int $attempts, ?int $code): void
    {
        if (!Uuid::isValid($id)) {
            return;
        }
        $this->connection->update(self::TABLE, [
            'webhook_status' => $status,
            'webhook_attempts' => max(0, min(255, $attempts)),
            'webhook_code' => $code !== null ? max(0, min(65535, $code)) : null,
            'webhook_at' => $status === 'queued' ? null : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ], ['id' => Uuid::fromHexToBytes($id)]);
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
