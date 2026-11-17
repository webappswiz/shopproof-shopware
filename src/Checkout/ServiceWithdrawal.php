<?php declare(strict_types=1);

namespace Shopproof\Compliance\Checkout;

use Shopproof\Compliance\Withdrawal\WithdrawalClock;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

/**
 * Withdrawal for services and dated bookings (Directive 2011/83/EU), as in the WooCommerce plugin:
 *
 * - Each product has a withdrawal type (product custom field shopproof_withdrawal_kind): empty
 *   (automatic: digital products are digital content, everything else goods), goods, service, or
 *   dated (leisure, travel, catering, accommodation or car hire on a fixed date, Art. 16(l)).
 * - A contract with any goods is a sales contract (Art. 2(5)); one of only services or digital
 *   content counts from the order day (Art. 9(2)(a) and (c)); one of only dated bookings has
 *   usually no right of withdrawal.
 * - Early start (Art. 8(8)): the request and the acknowledgement the shopper ticked at checkout,
 *   kept on the order as one readable text (order custom field shopproof_service_consent).
 * - The merchant enters on the order (Shopproof custom fields) the service period, the part
 *   provided and the day the service was fully provided (Art. 16(a)).
 * - The amount the customer pays for the part provided (Art. 14(3)), nothing without the request
 *   (Art. 14(4)(a)).
 *
 * Pure, so it is unit tested without a shop. Technical help, not legal advice.
 */
final class ServiceWithdrawal
{
    public const SETTING = 'ShopproofCompliance.config.serviceConsent';

    /** The product custom field with the withdrawal type. */
    public const KIND_FIELD = 'shopproof_withdrawal_kind';

    public const KINDS = ['goods', 'service', 'dated'];

    /** The order custom field that keeps what the shopper ticked, and when. */
    public const FIELD = 'shopproof_service_consent';

    /** The two checkbox names on the confirm page. */
    public const CONSENT_FIELD = 'shopproofServiceConsent';
    public const ACK_FIELD = 'shopproofServiceAck';

    /** The order custom fields the merchant fills in: service period, part provided, day done. */
    public const START_FIELD = 'shopproof_service_start';
    public const END_FIELD = 'shopproof_service_end';
    public const SHARE_FIELD = 'shopproof_service_share';
    public const DONE_FIELD = 'shopproof_service_done';

    public const MODES = ['optional', 'required'];

    /**
     * The shop's choice: '' (off), optional or required.
     */
    public static function mode(mixed $setting): string
    {
        return \is_string($setting) && \in_array($setting, self::MODES, true) ? $setting : '';
    }

    /**
     * What a product is for withdrawal: goods, service, dated or digital.
     *
     * @param array<mixed>|null $customFields the product's custom fields (as in a line item payload)
     */
    public static function kindOf(?string $productType, ?array $customFields): string
    {
        $chosen = $customFields[self::KIND_FIELD] ?? null;
        if (\is_string($chosen) && \in_array($chosen, self::KINDS, true)) {
            return $chosen;
        }

        return $productType === 'digital' ? 'digital' : 'goods';
    }

    /**
     * What a cart line item is for withdrawal (its product type and custom fields are in its
     * payload; a download in Shopware before 6.7.6 has only the "is-download" state).
     */
    public static function lineItemKind(LineItem $item): string
    {
        $fields = $item->getPayloadValue('customFields');
        $type = $item->getPayloadValue('productType');

        return self::kindOf(DigitalConsent::isDigital($item) ? 'digital' : (\is_string($type) ? $type : null), \is_array($fields) ? $fields : null);
    }

    /**
     * What a cart makes the contract (product line items only; an empty cart counts as goods).
     *
     * @param iterable<LineItem> $items the cart's line items, flat
     */
    public static function cartKind(iterable $items): string
    {
        $kinds = [];
        foreach ($items as $item) {
            if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE) {
                $kinds[] = self::lineItemKind($item);
            }
        }

        return self::contractKind($kinds);
    }

    /**
     * Whether a cart holds a service, so the early start boxes are shown.
     *
     * @param iterable<LineItem> $items
     */
    public static function hasService(iterable $items): bool
    {
        foreach ($items as $item) {
            if ($item->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE && self::lineItemKind($item) === 'service') {
                return true;
            }
        }

        return false;
    }

    /**
     * A day (Y-m-d) from what Shopware's date field saved: a plain day, or a time that stands for
     * midnight in the admin user's time zone (so it is moved by 12 hours before the day is read,
     * which is right for every zone between UTC-12 and UTC+12). '' when empty or not a date.
     */
    public static function dateOf(mixed $value): string
    {
        if (!\is_string($value) || trim($value) === '') {
            return '';
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return WithdrawalClock::cleanDate($value);
        }
        try {
            $time = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return '';
        }

        return $time->setTimezone(new \DateTimeZone('UTC'))->modify('+12 hours')->format('Y-m-d');
    }

    /**
     * The part provided the merchant entered (0 to 100), or null when empty.
     */
    public static function shareOf(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value) || (\is_string($value) && is_numeric(trim($value)))) {
            return min(100.0, max(0.0, (float) $value));
        }

        return null;
    }

    /**
     * What a list of item kinds makes the contract: goods, service or dated.
     *
     * @param list<string> $kinds
     */
    public static function contractKind(array $kinds): string
    {
        if ($kinds === [] || \in_array('goods', $kinds, true)) {
            return 'goods';
        }
        if (array_diff($kinds, ['dated']) === []) {
            return 'dated';
        }

        return 'service';
    }

    /**
     * What is wrong with the two ticks: '' when fine, 'required' (both needed), or 'pair' (in
     * optional mode one box alone is not a request: both or neither).
     */
    public static function pairError(string $mode, bool $consent, bool $ack): string
    {
        if ($mode === 'required' && (!$consent || !$ack)) {
            return 'required';
        }
        if ($mode === 'optional' && $consent !== $ack) {
            return 'pair';
        }

        return '';
    }

    /**
     * The text saved on the order: the time in UTC, then each statement as the shopper saw it.
     */
    public static function record(\DateTimeInterface $at, string $consent, string $ack): string
    {
        return DigitalConsent::record($at, $consent, $ack);
    }

    /**
     * Whether an order's custom fields hold the request and acknowledgement.
     *
     * @param array<mixed>|null $customFields
     */
    public static function given(?array $customFields): bool
    {
        $value = $customFields[self::FIELD] ?? null;

        return \is_string($value) && substr_count($value, '[x]') >= 2;
    }

    /**
     * What the customer owes for the service, from plain facts (Art. 14(3) and (4)). price: what
     * the services cost in the order, with tax; consent: early start asked and acknowledged;
     * received: day the withdrawal arrived; start and end: the service period ('' when unknown);
     * share: 0 to 100 when the merchant entered the part provided, else null.
     *
     * @param array{price: float, consent: bool, received: string, start: string, end: string, share: float|null} $facts
     *
     * @return array{state: string, share: float, owed: float, refund: float, days: int, of: int}
     *         state: no_consent, not_started, share, days or unknown (period needed)
     */
    public static function owed(array $facts): array
    {
        $price = max(0.0, $facts['price']);
        $out = ['state' => 'unknown', 'share' => 0.0, 'owed' => 0.0, 'refund' => $price, 'days' => 0, 'of' => 0];
        if (!$facts['consent']) {
            $out['state'] = 'no_consent';

            return $out;
        }
        if ($facts['share'] !== null) {
            $share = min(100.0, max(0.0, $facts['share'])) / 100;
            $state = 'share';
        } elseif ($facts['start'] !== '' && $facts['received'] < $facts['start']) {
            $out['state'] = 'not_started';

            return $out;
        } elseif ($facts['start'] !== '' && $facts['end'] !== '' && $facts['end'] > $facts['start']) {
            $of = self::daysBetween($facts['start'], $facts['end']);
            $days = min($of, self::daysBetween($facts['start'], $facts['received']));
            $share = $days / $of;
            $state = 'days';
            $out['days'] = $days;
            $out['of'] = $of;
        } else {
            return $out;
        }
        $owed = round($price * $share, 2);

        return ['state' => $state, 'share' => round($share * 100, 1), 'owed' => $owed, 'refund' => round($price - $owed, 2), 'days' => $out['days'], 'of' => $out['of']];
    }

    private static function daysBetween(string $from, string $to): int
    {
        $utc = new \DateTimeZone('UTC');

        return (int) round(((new \DateTimeImmutable($to . ' 00:00:00', $utc))->getTimestamp() - (new \DateTimeImmutable($from . ' 00:00:00', $utc))->getTimestamp()) / 86400);
    }
}
