<?php declare(strict_types=1);

namespace Shopproof\Compliance\ProofLog;

/**
 * The hash chain of the proof log (decision 0013), the same scheme as the WooCommerce plugin: each
 * entry's hash is SHA-256 over the previous entry's hash, its time, type and summary, so changing
 * or deleting an entry breaks every hash after it. Pure, so it is unit tested without Shopware.
 */
final class ProofChain
{
    /** The previous hash of the very first entry. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private function __construct()
    {
    }

    public static function entryHash(string $prevHash, string $createdAt, string $type, string $summary): string
    {
        return hash('sha256', $prevHash . "\n" . $createdAt . "\n" . $type . "\n" . $summary);
    }

    /**
     * The summary as stored: keys sorted, slashes and Unicode unescaped.
     *
     * @param array<mixed> $summary
     */
    public static function encode(array $summary): string
    {
        ksort($summary);
        $json = json_encode($summary, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return \is_string($json) ? $json : '{}';
    }

    /**
     * Checks entries in order. $prev is the hash the first entry must follow, or null to accept the
     * first entry's own previous hash (the oldest entries may have been removed by the retention).
     *
     * @param iterable<array{id: int, created_at: string, type: string, summary: string, prev_hash: string, hash: string}> $rows
     *
     * @return array{ok: bool, checked: int, brokenAt: int, last: string|null}
     */
    public static function verify(iterable $rows, ?string $prev = null): array
    {
        $checked = 0;
        foreach ($rows as $row) {
            $expected = self::entryHash($row['prev_hash'], $row['created_at'], $row['type'], $row['summary']);
            if (!hash_equals($expected, $row['hash']) || ($prev !== null && !hash_equals($prev, $row['prev_hash']))) {
                return ['ok' => false, 'checked' => $checked, 'brokenAt' => $row['id'], 'last' => $prev];
            }
            $prev = $row['hash'];
            ++$checked;
        }

        return ['ok' => true, 'checked' => $checked, 'brokenAt' => 0, 'last' => $prev];
    }

    /**
     * Whether the newest entry is the one the shop wrote last, so removing the newest entries or
     * emptying the table is found too. $last is the newest entry's hash (null when the table is
     * empty), $head the hash stored on each write and $pruned the hash of the last entry the
     * retention removed (both null before the first write or the first removal).
     */
    public static function anchored(?string $last, ?string $head, ?string $pruned): bool
    {
        if ($head === null) {
            return $last === null;
        }
        if ($last === null) {
            return $pruned !== null && hash_equals($head, $pruned);
        }

        return hash_equals($head, $last);
    }

    /**
     * What the log keeps about a withdrawal: its reference, time, country and a hash of the
     * record. No name, email address or order number, so erasing the record for a privacy request
     * leaves the log intact.
     *
     * @param array<string, mixed> $record
     *
     * @return array{reference: string, received_at: string, country: string, record_hash: string}
     */
    public static function withdrawalSummary(array $record, string $reference, string $receivedAt, string $country): array
    {
        return [
            'reference' => $reference,
            'received_at' => $receivedAt,
            'country' => $country,
            'record_hash' => hash('sha256', self::encode($record)),
        ];
    }

    /**
     * One CSV line. Cells starting with = + - @ get a leading apostrophe, so a spreadsheet never
     * runs them as formulas.
     *
     * @param list<string> $cells
     */
    public static function csvLine(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            if ($cell !== '' && \in_array($cell[0], ['=', '+', '-', '@'], true)) {
                $cell = "'" . $cell;
            }
            $out[] = '"' . str_replace('"', '""', $cell) . '"';
        }

        return implode(',', $out) . "\r\n";
    }
}
