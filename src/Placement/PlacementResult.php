<?php declare(strict_types=1);

namespace Shopproof\Compliance\Placement;

/**
 * The last placement check, as counts only: pages that passed, need a look, failed or could not
 * be checked, and how many of the passed or looked-at pages are ones where the withdrawal link was
 * expected and found (`linked`). The withdrawal page itself always passes, because it needs no
 * link to itself, so only `linked` shows that a link was found. The check itself runs in the merchant's browser; only these numbers and the time
 * are stored in the shop's own settings, for the overview. Pure, so it is unit tested alone.
 */
final class PlacementResult
{
    public const CONFIG = 'ShopproofCompliance.placementResult';

    private const KEYS = ['pass', 'warn', 'fail', 'skipped', 'linked'];

    private function __construct()
    {
    }

    /**
     * Counts from the admin page, each a whole number from 0 to 100.
     *
     * @param array<mixed> $input
     *
     * @return array{time: int, pass: int, warn: int, fail: int, skipped: int, linked: int}
     */
    public static function clean(array $input, int $now): array
    {
        $out = ['time' => $now, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skipped' => 0, 'linked' => 0];
        foreach (self::KEYS as $key) {
            $value = $input[$key] ?? 0;
            $out[$key] = \is_int($value) || (\is_string($value) && ctype_digit($value)) ? max(0, min(100, (int) $value)) : 0;
        }
        // A found link is on a page that passed or needs a look; a result saved before 0.7.0 has
        // no count and so proves nothing until the check runs again.
        $out['linked'] = min($out['linked'], $out['pass'] + $out['warn']);

        return $out;
    }

    /**
     * The stored result, or null when the check never ran or the value is broken.
     *
     * @return array{time: int, pass: int, warn: int, fail: int, skipped: int, linked: int}|null
     */
    public static function read(mixed $stored): ?array
    {
        if (!\is_array($stored) || !\is_int($stored['time'] ?? null) || $stored['time'] <= 0) {
            return null;
        }

        return [...self::clean($stored, $stored['time']), 'time' => $stored['time']];
    }

    /**
     * Done only when the link was found on at least one page that needs it and no page failed: a
     * check where every page was skipped or blocked, or only the withdrawal page itself opened,
     * proves nothing.
     *
     * @param array{fail: int, linked: int}|null $result
     */
    public static function done(?array $result): bool
    {
        return $result !== null && $result['fail'] === 0 && $result['linked'] > 0;
    }
}
