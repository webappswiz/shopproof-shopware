<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Psr\Cache\CacheItemPoolInterface;

/**
 * A fixed one hour window per key: up to LIMIT tries, then none until the hour that began with
 * the first try is over. The window's start is kept with the count and every save sets the same
 * end, so the pool's own default lifetime never stretches the lockout and new tries never push
 * it back.
 */
final class LookupLimit
{
    public const LIMIT = 20;
    public const WINDOW = 3600;

    public static function allow(CacheItemPoolInterface $cache, string $key, int $now, int $limit = self::LIMIT, int $window = self::WINDOW): bool
    {
        $item = $cache->getItem($key);
        $value = $item->isHit() ? $item->get() : null;
        $start = \is_array($value) && \is_int($value['start'] ?? null) ? $value['start'] : null;
        $count = \is_array($value) && \is_int($value['count'] ?? null) ? $value['count'] : 0;
        if ($start === null || $now >= $start + $window || $start > $now) {
            $start = $now;
            $count = 0;
        }
        if ($count >= $limit) {
            return false;
        }
        $item->set(['start' => $start, 'count' => $count + 1]);
        $item->expiresAt((new \DateTimeImmutable('@' . ($start + $window))));
        $cache->save($item);

        return true;
    }
}
