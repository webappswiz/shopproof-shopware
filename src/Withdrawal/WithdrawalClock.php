<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Shopproof\Compliance\Checkout\ServiceWithdrawal;

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
 * Services and dated bookings (ServiceWithdrawal): an order of only services or digital content
 * counts from the order day (Art. 9(2)(a) and (c)); with any goods it is a sales contract (Art.
 * 2(5)). A service fully provided before the withdrawal, with the request to start early and the
 * acknowledgement on record, has no right of withdrawal (Art. 16(a)), and neither has an order of
 * only dated leisure, travel, catering or accommodation bookings (Art. 16(l)). The clock says
 * "check" for both, never "late": the merchant decides.
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
     * For services: kind 'service' counts from concluded (the order day); performed is the day the
     * service was fully provided, consent the early start request and acknowledgement; exempt
     * says every item is a dated booking; digitalConsent says the order holds no service items and
     * has the digital content consent record (Art. 16(m)), so the right may have ended once the
     * download started.
     *
     * @param array{received: string, today: string, delivered?: string, deliveredSource?: string, notShipped?: bool, refunded?: string, partlyRefunded?: string, days?: int, kind?: string, concluded?: string, performed?: string, consent?: bool, exempt?: bool, digitalConsent?: bool} $facts
     *
     * @return array{window: string, windowEnd: string, daysOver: int, refund: string, refundDue: string, daysLeft: int, returnBy: string, refunded: string, partlyRefunded: string, basis: string}
     *         window: inside, before_delivery, late, check, unknown, performed, digital or exempt;
     *         refund: paid, due, today, overdue or none (not owed while the withdrawal is late, or
     *         while the right may not apply); basis: delivery or order.
     */
    public static function assess(array $facts): array
    {
        $received = $facts['received'];
        $delivered = $facts['delivered'] ?? '';
        $refunded = $facts['refunded'] ?? '';
        $partly = $refunded === '' ? ($facts['partlyRefunded'] ?? '') : '';

        $service = ($facts['kind'] ?? '') === 'service' && ($facts['concluded'] ?? '') !== '';
        $performed = $facts['performed'] ?? '';

        $windowEnd = '';
        $daysOver = 0;
        if ($facts['exempt'] ?? false) {
            $window = 'exempt';
        } elseif ($service && $performed !== '' && $performed <= $received && ($facts['consent'] ?? false)) {
            $window = 'performed';
        } elseif ($service && ($facts['digitalConsent'] ?? false)) {
            // Digital content with the consent and acknowledgement on record: the right ends once
            // the download started (Art. 16(m)). Shopproof cannot see that, so the merchant checks.
            $window = 'digital';
        } elseif ($service) {
            $windowEnd = self::windowEnd($facts['concluded'], $facts['days'] ?? self::DAYS);
            if ($received <= $windowEnd) {
                $window = 'inside';
            } else {
                // The order day is certain, so a withdrawal after the window is late.
                $daysOver = self::daysBetween($windowEnd, $received);
                $window = 'late';
            }
        } elseif ($delivered !== '') {
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
            \in_array($window, ['late', 'exempt', 'performed', 'digital'], true) => 'none',
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
            'basis' => $service ? 'order' : 'delivery',
        ];
    }

    /**
     * The clock for a stored record and what its matched order says. Times are UTC ISO strings,
     * turned into days in the given time zone. The status "Refund sent" counts as paid on its day;
     * otherwise a payment Shopware shows as refunded does. A partial refund counts only when made
     * after the withdrawal arrived. The period stored with the record wins over the current setting.
     *
     * Service orders (order kind 'service') count from the order day; the order's serviceDone is the
     * day the merchant entered as fully provided. For an order with service items whose withdrawal
     * arrived inside the window, owed is what the customer pays for the part provided
     * (ServiceWithdrawal::owed), with the order's currency code. An order of only digital content
     * with the digital consent record shows "digital" (check) and no refund clock.
     *
     * @param array{submittedAt: string, status: string, refundSentAt: string, deliveredOn: string, periodDays: int} $record
     * @param array{shippedAt: string, open: bool, refundedAt: string, partlyRefundedAt: string, paymentState: string, kind?: string, orderedAt?: string, consent?: bool, serviceItems?: bool, digitalConsent?: bool, serviceDone?: string, servicePrice?: float, serviceStart?: string, serviceEnd?: string, serviceShare?: float|null, currency?: string} $order
     *
     * @return array{window: string, windowEnd: string, daysOver: int, refund: string, refundDue: string, daysLeft: int, returnBy: string, refunded: string, partlyRefunded: string, basis: string, delivered: string, deliveredSource: string, tone: string, owed: array{state: string, share: float, owed: float, refund: float, days: int, of: int, currency: string}|null}|null
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

        $kind = $order['kind'] ?? 'goods';
        $serviceItems = $order['serviceItems'] ?? ($kind === 'service');
        $clock = self::assess([
            'kind' => $kind,
            'concluded' => self::day($order['orderedAt'] ?? '', $zone),
            'performed' => self::cleanDate($order['serviceDone'] ?? ''),
            'consent' => $order['consent'] ?? false,
            'exempt' => $kind === 'dated',
            'digitalConsent' => !$serviceItems && ($order['digitalConsent'] ?? false),
            'received' => $received,
            'today' => $today,
            'delivered' => $delivered,
            'deliveredSource' => $source,
            'notShipped' => $order['open'],
            'refunded' => $refunded,
            'partlyRefunded' => $partly,
            'days' => $record['periodDays'] >= self::DAYS ? $record['periodDays'] : $defaultDays,
        ]);

        $owed = null;
        // Only for service items, and only while the withdrawal is in time: a late one owes no refund.
        if ($clock['basis'] === 'order' && $serviceItems && $clock['window'] === 'inside') {
            $owed = ServiceWithdrawal::owed([
                'price' => (float) ($order['servicePrice'] ?? 0.0),
                'consent' => $order['consent'] ?? false,
                'received' => $received,
                'start' => self::cleanDate($order['serviceStart'] ?? ''),
                'end' => self::cleanDate($order['serviceEnd'] ?? ''),
                'share' => $order['serviceShare'] ?? null,
            ]) + ['currency' => (string) ($order['currency'] ?? '')];
        }

        return $clock + ['delivered' => $delivered, 'deliveredSource' => $source, 'tone' => self::tone($clock), 'owed' => $owed];
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
            'check', 'performed', 'digital', 'exempt' => 'warn',
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
