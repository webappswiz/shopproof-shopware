<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

/**
 * Keeps only the ticked items that are on the order: the picker sends "2 x Name" lines in a
 * hidden field, which anyone can change, so a line is kept when the order has an item of that
 * name and at least that many of it. Lines that do not fit are left out of the record, the
 * emails and the webhook; nothing ticked still means the whole order.
 */
final class PickedItems
{
    /**
     * @param list<array{name: string, quantity: int}> $orderItems
     */
    public static function onlyOrdered(string $picked, array $orderItems): string
    {
        $ordered = [];
        foreach ($orderItems as $item) {
            $name = trim($item['name']);
            $ordered[$name] = ($ordered[$name] ?? 0) + max(0, $item['quantity']);
        }
        $kept = [];
        foreach (preg_split('/\R/', $picked) ?: [] as $line) {
            if (preg_match('/^\s*(\d{1,4}) x (.+?)\s*$/u', $line, $m) !== 1) {
                continue;
            }
            $count = (int) $m[1];
            $name = $m[2];
            if ($count < 1 || !isset($ordered[$name]) || isset($kept[$name]) || $count > $ordered[$name]) {
                continue;
            }
            $kept[$name] = $count . ' x ' . $name;
        }

        return implode("\n", array_values($kept));
    }
}
