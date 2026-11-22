<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/**
 * What the delivery and payment line names for a storefront: its active countries that allow
 * shipping and its active payment methods, in the storefront language (else the system language),
 * in the order Shopware shows them. Read once per request.
 */
class DeliveryLineFacts
{
    /** @var array<string, array{countries: list<string>, payments: list<string>}> */
    private array $cache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{countries: list<string>, payments: list<string>}
     */
    public function collect(string $salesChannelId, string $languageId): array
    {
        $key = $salesChannelId . ':' . $languageId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $params = [
            'channel' => hex2bin($salesChannelId),
            'langs' => [hex2bin($languageId), hex2bin(Defaults::LANGUAGE_SYSTEM)],
            'lang' => hex2bin($languageId),
        ];
        $types = ['langs' => ArrayParameterType::BINARY];
        $countries = $this->connection->fetchFirstColumn(
            'SELECT COALESCE(MAX(CASE WHEN t.language_id = :lang THEN t.name END), MAX(t.name)) AS name
             FROM sales_channel_country scc
             INNER JOIN country c ON c.id = scc.country_id
             LEFT JOIN country_translation t ON t.country_id = c.id AND t.language_id IN (:langs)
             WHERE scc.sales_channel_id = :channel AND c.active = 1 AND c.shipping_available = 1
             GROUP BY c.id, c.position
             ORDER BY c.position, name',
            $params,
            $types
        );
        $payments = $this->connection->fetchFirstColumn(
            'SELECT COALESCE(MAX(CASE WHEN t.language_id = :lang THEN t.name END), MAX(t.name)) AS name
             FROM sales_channel_payment_method scp
             INNER JOIN payment_method p ON p.id = scp.payment_method_id
             LEFT JOIN payment_method_translation t ON t.payment_method_id = p.id AND t.language_id IN (:langs)
             WHERE scp.sales_channel_id = :channel AND p.active = 1
             GROUP BY p.id, p.position
             ORDER BY p.position, name',
            $params,
            $types
        );

        return $this->cache[$key] = [
            'countries' => array_values(array_filter(array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $countries), static fn (string $v): bool => $v !== '')),
            'payments' => array_values(array_filter(array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $payments), static fn (string $v): bool => $v !== '')),
        ];
    }
}
