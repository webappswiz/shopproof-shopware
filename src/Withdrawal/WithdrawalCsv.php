<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Shopproof\Compliance\ProofLog\ProofChain;

/**
 * The withdrawals list as a CSV file, as the WooCommerce plugin's "Export all as CSV": one row per
 * withdrawal, newest first, with formula-safe cells (ProofChain::csvLine). Headings in English or
 * German, the admin's language. Pure, so it is unit tested without Shopware.
 */
final class WithdrawalCsv
{
    private const HEADINGS = [
        'en' => ['Reference', 'Received (UTC)', 'Sales channel', 'First name', 'Last name', 'Email address', 'Order number given', 'Matched order', 'Order date', 'Items', 'Comment', 'Status', 'Return arrived (UTC)', 'Refund sent (UTC)', 'Goods received on'],
        'de' => ['Referenz', 'Eingegangen (UTC)', 'Verkaufskanal', 'Vorname', 'Nachname', 'E-Mail-Adresse', 'Angegebene Bestellnummer', 'Zugeordnete Bestellung', 'Bestelldatum', 'Artikel', 'Kommentar', 'Status', 'Rücksendung angekommen (UTC)', 'Erstattung gesendet (UTC)', 'Ware erhalten am'],
    ];

    private const STATES = [
        'en' => ['received' => 'Received', 'return_arrived' => 'Return arrived', 'refund_sent' => 'Refund sent'],
        'de' => ['received' => 'Eingegangen', 'return_arrived' => 'Rücksendung angekommen', 'refund_sent' => 'Erstattung gesendet'],
    ];

    public static function lang(string $locale): string
    {
        return str_starts_with(strtolower($locale), 'de') ? 'de' : 'en';
    }

    public static function header(string $lang): string
    {
        // The byte order mark lets spreadsheet apps read the file as UTF-8.
        return "\xEF\xBB\xBF" . ProofChain::csvLine(self::HEADINGS[$lang] ?? self::HEADINGS['en']);
    }

    /**
     * One withdrawal from WithdrawalStore::list() as a CSV line.
     *
     * @param array<string, mixed> $item
     */
    public static function row(array $item, string $lang): string
    {
        $s = static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '';
        $utc = static function (mixed $v): string {
            if (!\is_string($v) || $v === '') {
                return '';
            }
            try {
                return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                return '';
            }
        };
        $states = self::STATES[$lang] ?? self::STATES['en'];
        $status = $s($item['status'] ?? '');

        return ProofChain::csvLine([
            $s($item['id'] ?? ''),
            $utc($item['submittedAt'] ?? ''),
            $s($item['salesChannel'] ?? ''),
            $s($item['firstName'] ?? ''),
            $s($item['lastName'] ?? ''),
            $s($item['email'] ?? ''),
            $s($item['contractNumber'] ?? ''),
            $s($item['orderNumber'] ?? ''),
            $s($item['orderDate'] ?? ''),
            $s($item['items'] ?? ''),
            $s($item['comment'] ?? ''),
            $states[$status] ?? $status,
            $utc($item['returnArrivedAt'] ?? ''),
            $utc($item['refundSentAt'] ?? ''),
            $s($item['deliveredOn'] ?? ''),
        ]);
    }
}
