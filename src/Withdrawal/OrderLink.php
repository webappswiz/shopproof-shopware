<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

/**
 * The withdrawal link for one order, as in the WooCommerce plugin's one-tap withdrawal: the
 * withdrawal page address plus the order's id and a signature (HMAC-SHA256 over the order id and
 * the customer's email, keyed with the shop's own secret). The page fills in the form only when
 * the signature fits, so a guessed or changed id shows an empty form. No personal details go into
 * the address. Also the key of the customer's status page link. Pure, so it is unit tested
 * without Shopware.
 */
final class OrderLink
{
    public const ORDER = 'spOrder';
    public const TOKEN = 'spToken';

    public function __construct(private readonly string $secret)
    {
    }

    public function token(string $orderId, string $email): string
    {
        return substr(hash_hmac('sha256', strtolower($orderId) . "\n" . mb_strtolower(trim($email)), 'shopproof-withdrawal|' . $this->secret), 0, 32);
    }

    public function verify(string $orderId, string $email, string $token): bool
    {
        return $orderId !== '' && trim($email) !== '' && $token !== '' && hash_equals($this->token($orderId, $email), $token);
    }

    /**
     * The page address with the order's id and signature added.
     */
    public function url(string $page, string $orderId, string $email): string
    {
        if ($page === '' || $orderId === '' || trim($email) === '') {
            return $page;
        }
        $parts = explode('#', $page, 2);
        $base = $parts[0];
        $fragment = $parts[1] ?? null;

        return $base . (str_contains($base, '?') ? '&' : '?')
            . http_build_query([self::ORDER => strtolower($orderId), self::TOKEN => $this->token($orderId, $email)])
            . ($fragment !== null ? '#' . $fragment : '');
    }

    /**
     * The key of a withdrawal's status page link: a signature over the record's id with its own
     * prefix, so an order link's signature never opens a status page.
     */
    public function statusToken(string $requestId): string
    {
        return substr(hash_hmac('sha256', strtolower($requestId), 'shopproof-status|' . $this->secret), 0, 32);
    }

    public function verifyStatus(string $requestId, string $token): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $requestId) === 1 && $token !== '' && hash_equals($this->statusToken($requestId), $token);
    }

    /**
     * The order id from a request's query, when it looks like one.
     *
     * @param array<mixed> $query
     *
     * @return array{order: string, token: string}|null
     */
    public static function fromQuery(array $query): ?array
    {
        $order = $query[self::ORDER] ?? null;
        $token = $query[self::TOKEN] ?? null;
        if (!\is_string($order) || !\is_string($token) || !preg_match('/^[0-9a-f]{32}$/', $order) || !preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }

        return ['order' => $order, 'token' => $token];
    }
}
