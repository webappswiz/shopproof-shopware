<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

/**
 * Deadline clock for withdrawals, as in the WooCommerce plugin: is a withdrawal inside the window,
 * and when is the refund due. Technical help, not legal advice: the merchant decides.
 *
 * Directive 2011/83/EU and Regulation (EEC, Euratom) No 1182/71 on time limits:
 * - Window (Art. 9): 14 days (or the shop's longer period) from the day the customer received the
 *   goods. A window that would end on a Saturday or Sunday runs to the next Monday. Public holidays
 *   also extend it but differ by region, so the clock only says so. A withdrawal sent before
 *   delivery is always in time.
 * - Refund (Art. 13): within 14 days from the day the shop was told. Not moved past weekends, so
 *   the clock never shows a later date than the law may allow.
 * - Goods back (Art. 14): within 14 days of withdrawing.
 *
 * Without a delivery date the merchant entered, the day Shopware marked the delivery as shipped
 * stands in. The goods arrive later than that, so an estimate can say "in time" but never "late",
 * only "check". Pure, so it is unit tested without Shopware.
 */
final class WithdrawalClock
{
    public const DAYS = 14;

    public const SOON = 3;

    /**
     * A Y-m-d date, or '' when the value is not a real calendar date.
     */
    public static function cleanDate(mixed $value): string
    {
        if (!\is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) {
            return '';
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
    }

    public static function addDays(string $day, int $days): string
    {
        return (new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format('Y-m-d');
    }

    public static function daysBetween(string $from, string $to): int
    {
        $utc = new \DateTimeZone('UTC');

        return (int) round(((new \DateTimeImmutable($to . ' 00:00:00', $utc))->getTimestamp() - (new \DateTimeImmutable($from . ' 00:00:00', $utc))->getTimestamp()) / 86400);
    }

    /**
     * The last day of the window for goods received on a day.
     */
    public static function windowEnd(string $delivered, int $days = self::DAYS): string
    {
        $end = self::addDays($delivered, max(self::DAYS, $days));
        $weekday = (int) (new \DateTimeImmutable($end . ' 00:00:00', new \DateTimeZone('UTC')))->format('N');

        return $weekday >= 6 ? self::addDays($end, 8 - $weekday) : $end;
    }

    /**
     * The clock from plain facts, all days as Y-m-d. received: the day the withdrawal arrived;
     * today: the shop's today; delivered: the day the goods arrived ('' when unknown) with
     * deliveredSource 'entered' or 'shipped'; notShipped: the order's delivery is still open;
     * refunded: the day the refund was paid in full; partlyRefunded: the day of the latest partial
     * refund since the withdrawal (it does not stop the clock); days: the shop's period.
     *
     * @param array{received: string, today: string, delivered?: string, deliveredSource?: string, notShipped?: bool, refunded?: string, partlyRefunded?: string, days?: int} $facts
     *
     * @return array{window: string, windowEnd: string, daysOver: int, refund: string, refundDue: string, daysLeft: int, returnBy: string, refunded: string, partlyRefunded: string}
     *         window: inside, before_delivery, late, check or unknown;
     *         refund: paid, due, today, overdue or none (not owed while the withdrawal is late).
     */
    public static function assess(array $facts): array
    {
        $received = $facts['received'];
        $delivered = $facts['delivered'] ?? '';
        $refunded = $facts['refunded'] ?? '';
        $partly = $refunded === '' ? ($facts['partlyRefunded'] ?? '') : '';

        $windowEnd = '';
        $daysOver = 0;
        if ($delivered !== '') {
            $windowEnd = self::windowEnd($delivered, $facts['days'] ?? self::DAYS);
            if ($received < $delivered) {
                $window = 'before_delivery';
            } elseif ($received <= $windowEnd) {
                $window = 'inside';
            } else {
                $daysOver = self::daysBetween($windowEnd, $received);
                $window = ($facts['deliveredSource'] ?? '') === 'entered' ? 'late' : 'check';
            }
        } elseif ($facts['notShipped'] ?? false) {
            $window = 'before_delivery';
        } else {
            $window = 'unknown';
        }

        $refundDue = self::addDays($received, self::DAYS);
        $daysLeft = self::daysBetween($facts['today'], $refundDue);
        $refund = match (true) {
            $refunded !== '' => 'paid',
            $window === 'late' => 'none',
            $daysLeft > 0 => 'due',
            $daysLeft === 0 => 'today',
            default => 'overdue',
        };

        return [
            'window' => $window,
            'windowEnd' => $windowEnd,
            'daysOver' => $daysOver,
            'refund' => $refund,
            'refundDue' => $refundDue,
            'daysLeft' => $daysLeft,
            'returnBy' => self::addDays($received, self::DAYS),
            'refunded' => $refunded,
            'partlyRefunded' => $partly,
        ];
    }

    /**
     * The clock for a stored record and what its matched order says. Times are UTC ISO strings,
     * turned into days in the given time zone. The status "Refund sent" counts as paid on its day;
     * otherwise a payment Shopware shows as refunded does. A partial refund counts only when made
     * after the withdrawal arrived. The period stored with the record wins over the current setting.
     *
     * @param array{submittedAt: string, status: string, refundSentAt: string, deliveredOn: string, periodDays: int} $record
     * @param array{shippedAt: string, open: bool, refundedAt: string, partlyRefundedAt: string, paymentState: string} $order
     *
     * @return array{window: string, windowEnd: string, daysOver: int, refund: string, refundDue: string, daysLeft: int, returnBy: string, refunded: string, partlyRefunded: string, delivered: string, deliveredSource: string, tone: string}|null
     */
    public static function forRecord(array $record, array $order, \DateTimeZone $zone, string $today, int $defaultDays): ?array
    {
        $received = self::day($record['submittedAt'], $zone);
        if ($received === '') {
            return null;
        }
        $delivered = self::cleanDate($record['deliveredOn']);
        $source = $delivered !== '' ? 'entered' : '';
        if ($delivered === '' && $order['shippedAt'] !== '') {
            $delivered = self::day($order['shippedAt'], $zone);
            $source = $delivered !== '' ? 'shipped' : '';
        }
        $refunded = '';
        if ($record['status'] === 'refund_sent') {
            $refunded = self::day($record['refundSentAt'], $zone);
        } elseif ($order['paymentState'] === 'refunded') {
            $refunded = self::day($order['refundedAt'], $zone);
        }
        $partly = $order['partlyRefundedAt'] !== '' && $order['partlyRefundedAt'] >= $record['submittedAt'] ? self::day($order['partlyRefundedAt'], $zone) : '';

        $clock = self::assess([
            'received' => $received,
            'today' => $today,
            'delivered' => $delivered,
            'deliveredSource' => $source,
            'notShipped' => $order['open'],
            'refunded' => $refunded,
            'partlyRefunded' => $partly,
            'days' => $record['periodDays'] >= self::DAYS ? $record['periodDays'] : $defaultDays,
        ]);

        return $clock + ['delivered' => $delivered, 'deliveredSource' => $source, 'tone' => self::tone($clock)];
    }

    /**
     * A UTC ISO time as a day in a time zone, '' when unreadable.
     */
    public static function day(string $iso, \DateTimeZone $zone): string
    {
        if ($iso === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($iso))->setTimezone($zone)->format('Y-m-d');
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * The tone of a clock for the admin list: ok, warn, bad or none, the worse of window and refund.
     *
     * @param array{window: string, refund: string, daysLeft: int, partlyRefunded: string} $clock
     */
    public static function tone(array $clock): string
    {
        $window = match ($clock['window']) {
            'inside', 'before_delivery' => 'ok',
            'late' => 'bad',
            'check' => 'warn',
            default => 'none',
        };
        $refund = match ($clock['refund']) {
            'paid' => 'ok',
            'none' => 'none',
            'today', 'overdue' => 'bad',
            default => $clock['daysLeft'] <= self::SOON || $clock['partlyRefunded'] !== '' ? 'warn' : 'ok',
        };
        $rank = ['none' => 0, 'ok' => 1, 'warn' => 2, 'bad' => 3];

        return $rank[$window] >= $rank[$refund] ? $window : $refund;
    }
}
