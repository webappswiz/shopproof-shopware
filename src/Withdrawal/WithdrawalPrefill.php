<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/**
 * Fills in Shopware's withdrawal form from the signed link in the order emails and in the
 * customer account (OrderLink): name, email, order number and order date of that order. Nothing
 * is filled in when the signature does not fit the order's id and email, or the order belongs to
 * another sales channel, so the link cannot be used to read someone else's order.
 */
class WithdrawalPrefill
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderLink $link,
    ) {
    }

    /**
     * @param array<mixed> $query the request's query parameters
     *
     * @return array{orderId: string, firstName: string, lastName: string, email: string, contractNumber: string, orderDate: string}|null
     */
    public function fromQuery(array $query, ?string $salesChannelId): ?array
    {
        $keys = OrderLink::fromQuery($query);
        if ($keys === null) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(o.sales_channel_id)) AS channel, o.order_number, o.order_date_time, oc.email, oc.first_name, oc.last_name
             FROM `order` o INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
             WHERE o.id = :id AND o.version_id = :version LIMIT 1',
            ['id' => hex2bin($keys['order']), 'version' => hex2bin(Defaults::LIVE_VERSION)]
        );
        if (!\is_array($row)) {
            return null;
        }

        return self::fields($row, $keys['order'], $keys['token'], $salesChannelId, $this->link);
    }

    /**
     * The form values for an order row, when the token and sales channel fit. Pure, for the tests.
     *
     * @param array<mixed> $row
     *
     * @return array{orderId: string, firstName: string, lastName: string, email: string, contractNumber: string, orderDate: string}|null
     */
    public static function fields(array $row, string $orderId, string $token, ?string $salesChannelId, OrderLink $link): ?array
    {
        $email = \is_string($row['email'] ?? null) ? $row['email'] : '';
        if (!$link->verify($orderId, $email, $token)) {
            return null;
        }
        if ($salesChannelId !== null && \is_string($row['channel'] ?? null) && $row['channel'] !== strtolower($salesChannelId)) {
            return null;
        }
        $date = \is_string($row['order_date_time'] ?? null) ? substr($row['order_date_time'], 0, 10) : '';

        return [
            'orderId' => $orderId,
            'firstName' => \is_string($row['first_name'] ?? null) ? $row['first_name'] : '',
            'lastName' => \is_string($row['last_name'] ?? null) ? $row['last_name'] : '',
            'email' => $email,
            'contractNumber' => \is_string($row['order_number'] ?? null) ? $row['order_number'] : '',
            'orderDate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '',
        ];
    }
}
