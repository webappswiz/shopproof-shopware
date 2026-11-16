<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

use Shopproof\Compliance\Placement\PlacementResult;

/**
 * The top of the Shopproof page, as in the WooCommerce plugin's Overview tab: a setup checklist
 * for new shops (each step with the place where it is done) and one status card per feature.
 * Built from facts the page already reads; returns codes and numbers only, the admin holds the
 * words. A part that could not be checked never shows as done. Pure, so it is unit tested alone.
 */
final class Overview
{
    /** Proof log entries older than this mean the daily record stopped. */
    public const STALE_SECONDS = 2 * 86400;

    private function __construct()
    {
    }

    /**
     * @param array{
     *     check: array{supported: bool, ok: bool, shop: list<array{key: string, ok: bool}>, channels: list<array{checks: list<array{key: string, ok: bool}>}>, failedRecords: int, products: list<array{key: string, ok: bool}>},
     *     placement: array{time: int, pass: int, warn: int, fail: int, skipped: int, linked: int}|null,
     *     manufacturersWithoutDetails: int,
     *     productsWithoutMaker: int,
     *     products: int,
     *     registrationNumbers: int,
     *     withdrawals: array{total: int, overdue: int, soon: int},
     *     proof: array{available: bool, ok: bool, checked: int, lastSnapshot: int|null},
     *     rules: array{available: bool, count: int, version: string},
     *     now: int,
     * } $in
     *
     * @return array{steps: list<array{key: string, done: bool, optional: bool}>, done: int, required: int, cards: list<array{key: string, state: string, text: string, values: array<string, int|string>}>}
     */
    public static function build(array $in): array
    {
        $check = $in['check'];
        $button = self::buttonReady($check);
        $guarantee = self::productCheck($check, 'guaranteeNotice');
        $safetyDone = $in['manufacturersWithoutDetails'] === 0 && $in['productsWithoutMaker'] === 0;

        $steps = [];
        if ($check['supported']) {
            $steps[] = ['key' => 'button', 'done' => $button, 'optional' => false];
            $steps[] = ['key' => 'placement', 'done' => PlacementResult::done($in['placement']), 'optional' => false];
        }
        $steps[] = ['key' => 'safety', 'done' => $safetyDone, 'optional' => false];
        $steps[] = ['key' => 'guarantee', 'done' => $guarantee === true, 'optional' => false];
        $steps[] = ['key' => 'registrations', 'done' => $in['registrationNumbers'] > 0, 'optional' => true];

        $required = array_filter($steps, static fn (array $s): bool => !$s['optional']);
        $done = \count(array_filter($required, static fn (array $s): bool => $s['done']));

        return [
            'steps' => $steps,
            'done' => $done,
            'required' => \count($required),
            'cards' => [
                self::withdrawalCard($check, $button, $in['placement']),
                self::withdrawalsCard($check, $in['withdrawals']),
                self::safetyCard($in),
                self::card('guarantee', $guarantee === null ? 'warn' : ($guarantee ? 'good' : 'warn'), $guarantee === null ? 'unknown' : ($guarantee ? 'on' : 'off')),
                self::card('registrations', $in['registrationNumbers'] > 0 ? 'good' : 'info', $in['registrationNumbers'] > 0 ? 'some' : 'none', ['count' => $in['registrationNumbers']]),
                self::proofCard($in['proof'], $in['now']),
                $in['rules']['available']
                    ? self::card('rules', 'good', 'loaded', ['count' => $in['rules']['count'], 'version' => $in['rules']['version']])
                    : self::card('rules', 'bad', 'missing'),
            ],
        ];
    }

    /**
     * True when the shop-wide parts of the setup check pass (receipt email flow on and sending to
     * the customer, records table there) and every storefront has the button on, linked to a page
     * with the form. The button wording per language is its own line of the setup check.
     *
     * @param array{supported: bool, shop: list<array{key: string, ok: bool}>, channels: list<array{checks: list<array{key: string, ok: bool}>}>} $check
     */
    public static function buttonReady(array $check): bool
    {
        if (!$check['supported'] || $check['channels'] === [] || $check['shop'] === []) {
            return false;
        }
        foreach ($check['shop'] as $item) {
            if (!$item['ok']) {
                return false;
            }
        }
        foreach ($check['channels'] as $channel) {
            foreach ($channel['checks'] as $item) {
                if (\in_array($item['key'], ['buttonOn', 'page', 'form'], true) && !$item['ok']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array{products: list<array{key: string, ok: bool}>} $check
     */
    private static function productCheck(array $check, string $key): ?bool
    {
        foreach ($check['products'] as $item) {
            if ($item['key'] === $key) {
                return $item['ok'];
            }
        }

        return null;
    }

    /**
     * @param array{supported: bool, shop: list<array{key: string, ok: bool}>, channels: list<array{checks: list<array{key: string, ok: bool}>}>} $check
     * @param array{time: int, pass: int, warn: int, fail: int, skipped: int, linked: int}|null $placement
     *
     * @return array{key: string, state: string, text: string, values: array<string, int|string>}
     */
    private static function withdrawalCard(array $check, bool $button, ?array $placement): array
    {
        if (!$check['supported']) {
            return self::card('withdrawal', 'info', 'unsupported');
        }
        if (!$button) {
            return self::card('withdrawal', 'bad', 'notReady');
        }
        if ($placement === null) {
            return self::card('withdrawal', 'todo', 'notChecked');
        }
        if ($placement['fail'] > 0) {
            return self::card('withdrawal', 'bad', 'problem', ['count' => $placement['fail']]);
        }
        if ($placement['linked'] === 0) {
            return self::card('withdrawal', 'warn', 'noPages');
        }
        if ($placement['warn'] > 0 || $placement['skipped'] > 0) {
            return self::card('withdrawal', 'warn', 'look');
        }

        return self::card('withdrawal', 'good', 'working', ['count' => $placement['pass']]);
    }

    /**
     * @param array{failedRecords: int} $check
     * @param array{total: int, overdue: int, soon: int} $w
     *
     * @return array{key: string, state: string, text: string, values: array<string, int|string>}
     */
    private static function withdrawalsCard(array $check, array $w): array
    {
        if ($check['failedRecords'] > 0) {
            return self::card('withdrawals', 'bad', 'failed', ['count' => $check['failedRecords']]);
        }
        if ($w['overdue'] > 0) {
            return self::card('withdrawals', 'bad', 'overdue', ['count' => $w['overdue']]);
        }
        if ($w['soon'] > 0) {
            return self::card('withdrawals', 'warn', 'soon', ['count' => $w['soon']]);
        }
        if ($w['total'] > 0) {
            return self::card('withdrawals', 'good', 'received', ['count' => $w['total']]);
        }

        return self::card('withdrawals', 'info', 'none');
    }

    /**
     * @param array{manufacturersWithoutDetails: int, productsWithoutMaker: int, products: int} $in
     *
     * @return array{key: string, state: string, text: string, values: array<string, int|string>}
     */
    private static function safetyCard(array $in): array
    {
        if ($in['products'] === 0) {
            return self::card('safety', 'info', 'noProducts');
        }
        if ($in['productsWithoutMaker'] > 0) {
            return self::card('safety', 'warn', 'noMaker', ['count' => $in['productsWithoutMaker'], 'total' => $in['products']]);
        }
        if ($in['manufacturersWithoutDetails'] > 0) {
            return self::card('safety', 'warn', 'noDetails', ['count' => $in['manufacturersWithoutDetails']]);
        }

        return self::card('safety', 'good', 'complete');
    }

    /**
     * @param array{available: bool, ok: bool, checked: int, lastSnapshot: int|null} $proof
     *
     * @return array{key: string, state: string, text: string, values: array<string, int|string>}
     */
    private static function proofCard(array $proof, int $now): array
    {
        if (!$proof['available']) {
            return self::card('proof', 'bad', 'unavailable');
        }
        if (!$proof['ok']) {
            return self::card('proof', 'bad', 'changed');
        }
        if ($proof['checked'] === 0) {
            return self::card('proof', 'info', 'empty');
        }
        if ($proof['lastSnapshot'] === null) {
            return self::card('proof', 'warn', 'noSnapshot');
        }
        if ($proof['lastSnapshot'] < $now - self::STALE_SECONDS) {
            return self::card('proof', 'warn', 'stale', ['date' => gmdate('Y-m-d', $proof['lastSnapshot'])]);
        }

        return self::card('proof', 'good', 'recording', ['count' => $proof['checked']]);
    }

    /**
     * @param array<string, int|string> $values
     *
     * @return array{key: string, state: string, text: string, values: array<string, int|string>}
     */
    private static function card(string $key, string $state, string $text, array $values = []): array
    {
        return ['key' => $key, 'state' => $state, 'text' => $text, 'values' => $values];
    }
}
