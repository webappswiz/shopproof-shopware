<?php declare(strict_types=1);

namespace Shopproof\Compliance\Checkout;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Feature;

/**
 * Digital content consent (Directive 2011/83/EU, Article 16(m)): the two statements a shopper
 * gives before a shop starts supplying digital content within the withdrawal period, kept on the
 * order as one readable text (custom field shopproof_digital_consent), as the WooCommerce plugin
 * keeps them with its other checkout boxes. Pure, so it is unit tested without Shopware.
 */
final class DigitalConsent
{
    public const SETTING = 'ShopproofCompliance.config.digitalConsent';

    /** The order custom field that keeps what the shopper ticked, and when. */
    public const FIELD = 'shopproof_digital_consent';

    /** Shopware's own field for its box (it requires it for downloads), and the plugin's second box. */
    public const CONSENT_FIELD = 'revocation';
    public const ACK_FIELD = 'shopproofDigitalAck';

    /**
     * Whether a line item is digital: Shopware's product type (6.7), or the download state of
     * versions before it.
     */
    public static function isDigital(LineItem $item): bool
    {
        return $item->getPayloadValue('productType') === 'digital'
            || self::downloadState(static fn (): array => $item->getStates());
    }

    /**
     * Whether line item states (cart or order) hold "is-download", the only mark of a download
     * in Shopware 6.7 before 6.7.6. That API goes in 6.8 and is called only while it is still
     * active (.shopware-extension.yml).
     *
     * @param callable(): mixed $states
     */
    public static function downloadState(callable $states): bool
    {
        $legacy = false;
        $read = static function () use ($states, &$legacy): void {
            $list = $states();
            $legacy = \is_array($list) && \in_array('is-download', $list, true);
        };
        try {
            if (class_exists(Feature::class)) {
                Feature::callSilentIfInactive('v6.8.0.0', $read);
            } else {
                $read();
            }
        } catch (\Throwable) {
            return false;
        }

        return $legacy;
    }

    /**
     * @param iterable<LineItem> $items the cart's line items, flat
     */
    public static function hasDigital(iterable $items): bool
    {
        foreach ($items as $item) {
            if (self::isDigital($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a checkbox value counts as ticked ("on", "1", true).
     */
    public static function ticked(mixed $value): bool
    {
        return $value === true || (\is_string($value) && \in_array(strtolower(trim($value)), ['on', '1', 'true', 'yes'], true)) || $value === 1;
    }

    /**
     * The text saved on the order: the time in UTC, then each statement as the shopper saw it.
     */
    public static function record(\DateTimeInterface $at, string $consent, string $ack): string
    {
        $time = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') . ' UTC';
        $clean = static fn (string $text): string => trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return $time . "\n[x] " . $clean($consent) . "\n[x] " . $clean($ack);
    }

    /**
     * Whether an order's custom fields hold a consent record.
     *
     * @param array<mixed>|null $customFields
     */
    public static function given(?array $customFields): bool
    {
        $value = $customFields[self::FIELD] ?? null;

        return \is_string($value) && str_contains($value, '[x]');
    }
}
