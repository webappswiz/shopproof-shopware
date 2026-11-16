<?php declare(strict_types=1);

namespace Shopproof\Compliance\Placement;

/**
 * The key that lets the Shopproof page show the shop's own pages in a frame for the placement
 * check (decision 0024). Shopware answers every storefront page with "X-Frame-Options: deny", so
 * a page opened with a valid key answers "SAMEORIGIN" instead: only the shop's own admin, on the
 * same address, can frame it. The key is the expiry time plus an HMAC-SHA256 over it, keyed with
 * the shop's own secret and its own prefix, so it never opens anything else and stops working
 * after 30 minutes. It carries nothing about the merchant. Pure, so it is unit tested alone.
 */
final class PreviewToken
{
    public const PARAM = 'shopproofPreview';

    public const LIFETIME = 1800;

    public function __construct(private readonly string $secret)
    {
    }

    public function create(int $now): string
    {
        $expires = $now + self::LIFETIME;

        return $expires . '.' . $this->sign($expires);
    }

    public function verify(mixed $token, int $now): bool
    {
        if (!\is_string($token) || preg_match('/^(\d{10})\.([0-9a-f]{32})$/', $token, $m) !== 1) {
            return false;
        }
        $expires = (int) $m[1];
        if ($expires < $now || $expires > $now + self::LIFETIME) {
            return false;
        }

        return hash_equals($this->sign($expires), $m[2]);
    }

    /**
     * The address with the key added, keeping any query and fragment.
     */
    public function url(string $url, int $now): string
    {
        $parts = explode('#', $url, 2);
        $base = $parts[0];

        return $base . (str_contains($base, '?') ? '&' : '?') . self::PARAM . '=' . $this->create($now)
            . (isset($parts[1]) ? '#' . $parts[1] : '');
    }

    private function sign(int $expires): string
    {
        return substr(hash_hmac('sha256', (string) $expires, 'shopproof-preview|' . $this->secret), 0, 32);
    }
}
