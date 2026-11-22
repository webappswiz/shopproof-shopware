<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * The prior price of a price reduction (Directive 98/6/EC Art. 6a, decision 0040): the lowest
 * price that applied in the 30 days before the shop reduced the price. The same steps as
 * packages/prior-price/index.mjs, the WooCommerce plugin and the Shopify app; all of them run the
 * cases in packages/prior-price/vectors.json. Pure, unit tested alone.
 */
final class PriorPrice
{
    public const DAYS = 30;

    private const DAY = 86400;

    /** Shopware's default currency id (Defaults::CURRENCY), kept here so this class stays pure. */
    private const DEFAULT_CURRENCY = 'b7d2554b0ce847cd82f3ac9bd1c0dfca';

    private function __construct()
    {
    }

    /**
     * Works out the prior price from a price history.
     *
     * Status: known (the lowest price in the full window), since (the product is newer than the
     * window, so the lowest price since it was created), unknown (the history starts too late to
     * know) or none (no history).
     *
     * @param list<array{at: string, price: string}>|array<mixed> $rows Each row is the price that applied from "at" until the next row.
     *
     * @return array{status: string, price: string|null, since: string|null, start: string|null}
     */
    public static function compute(array $rows, string $createdAt = '', int $days = self::DAYS): array
    {
        $list = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $time = self::seconds($row['at'] ?? null);
            $price = isset($row['price']) && \is_scalar($row['price']) ? (string) $row['price'] : '';
            if ($time === null || $price === '' || !is_numeric($price)) {
                continue;
            }
            $list[] = ['t' => $time, 'price' => $price, 'n' => (float) $price];
        }
        usort($list, static fn (array $a, array $b): int => $a['t'] <=> $b['t']);
        $nothing = ['status' => 'none', 'price' => null, 'since' => null, 'start' => null];
        if ($list === []) {
            return $nothing;
        }
        $unknown = ['status' => 'unknown', 'price' => null, 'since' => null, 'start' => null];
        $firstSeen = $list[0]['t'];
        $created = self::seconds($createdAt);
        // History counts as complete since the product was created when Shopproof saw it within a day.
        $fromCreation = $created !== null && $firstSeen <= $created + self::DAY;

        // The reduction starts at the last time the price went down.
        $startIndex = -1;
        for ($i = \count($list) - 1; $i > 0; --$i) {
            if ($list[$i]['n'] < $list[$i - 1]['n']) {
                $startIndex = $i;

                break;
            }
        }

        if ($startIndex === -1) {
            if (!$fromCreation) {
                return $unknown;
            }

            return ['status' => 'since', 'price' => self::lowest($list), 'since' => self::iso(max((int) $created, 0)), 'start' => null];
        }

        $start = $list[$startIndex]['t'];
        $from = $start - $days * self::DAY;
        // Rows that applied at some time in [from, start): the one in force at "from" and every later one before the reduction.
        $inForce = -1;
        for ($i = 0; $i < $startIndex; ++$i) {
            if ($list[$i]['t'] <= $from) {
                $inForce = $i;
            }
        }
        $window = \array_slice($list, max($inForce, 0), $startIndex - max($inForce, 0));
        $low = self::lowest($window);

        if ($firstSeen <= $from) {
            return ['status' => 'known', 'price' => $low, 'since' => null, 'start' => self::iso($start)];
        }
        if ($fromCreation && $created > $from) {
            return ['status' => 'since', 'price' => $low, 'since' => self::iso((int) $created), 'start' => self::iso($start)];
        }
        $unknown['start'] = self::iso($start);

        return $unknown;
    }

    /**
     * Gross, net and list price in the shop's default currency from a product's price JSON, or
     * null when the product has no own price there.
     *
     * @return array{gross: string, net: string, list: string|null}|null
     */
    public static function defaultPrice(mixed $json): ?array
    {
        $prices = \is_string($json) ? json_decode($json, true) : $json;
        if (!\is_array($prices)) {
            return null;
        }
        foreach ($prices as $price) {
            if (!\is_array($price) || ($price['currencyId'] ?? null) !== self::DEFAULT_CURRENCY) {
                continue;
            }
            $gross = self::amount($price['gross'] ?? null);
            $net = self::amount($price['net'] ?? null);
            if ($gross === null || $net === null) {
                return null;
            }
            $list = \is_array($price['listPrice'] ?? null) ? self::amount($price['listPrice']['gross'] ?? null) : null;

            return ['gross' => $gross, 'net' => $net, 'list' => $list];
        }

        return null;
    }

    /**
     * The shopper line: "Lowest price in the 30 days before the reduction: EUR 89.99", or for a
     * newer product "Lowest price since 10 Nov 2026: EUR 100.00". '' when there is no price.
     *
     * @param array{status: string, price: string|null, since: string|null, start: string|null} $prior
     * @param callable(float): string                                                              $money
     * @param callable(int): string                                                                $date
     */
    public static function line(array $prior, NotesTexts $texts, string $lang, callable $money, callable $date): string
    {
        if ($prior['price'] === null || !\in_array($prior['status'], ['known', 'since'], true)) {
            return '';
        }
        $price = $money((float) $prior['price']);
        $since = $prior['status'] === 'since' ? self::seconds($prior['since']) : null;
        if ($since !== null) {
            return $texts->text('notices.priorPriceSince', $lang, ['date' => $date($since), 'price' => $price]);
        }

        return $texts->text('notices.priorPrice', $lang, ['price' => $price]);
    }

    /**
     * "2026-11-22T10:00:00Z" as a Unix time, or null.
     */
    public static function seconds(mixed $at): ?int
    {
        if (!\is_string($at) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $at) !== 1) {
            return null;
        }
        $time = strtotime($at);

        return $time === false ? null : $time;
    }

    public static function iso(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /**
     * A price as text without float noise ("84.03"), or null.
     */
    private static function amount(mixed $value): ?string
    {
        if (!\is_int($value) && !\is_float($value) && !(\is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float) $value;
        if ($number < 0) {
            return null;
        }
        $text = rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /**
     * @param list<array{t: int, price: string, n: float}> $rows
     */
    private static function lowest(array $rows): ?string
    {
        $low = null;
        foreach ($rows as $row) {
            if ($low === null || $row['n'] < $low['n']) {
                $low = $row;
            }
        }

        return $low === null ? null : $low['price'];
    }
}
