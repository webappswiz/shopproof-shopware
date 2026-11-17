<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Shopproof\Compliance\Withdrawal\PolicyMatch;

/**
 * The shop notes part of the setup check: products that look sold by weight or volume but have
 * no unit price, deposit type lines that cannot be read, chosen checkout boxes that cannot show,
 * whether the dispute statement is on the terms and imprint pages, and whether the withdrawal policy
 * (revocation page) names the same period and email as Shopproof. Pure, unit tested alone.
 *
 * None of these changes "ok" of the withdrawal check; each is shown as done or to do. A part that
 * was not checked is never shown as done.
 */
final class NotesCheck
{
    /**
     * @param array{unitPriceGaps?: list<array{id: string, name: string}>, depositBadLines?: list<string>, missingPolicyPages?: list<string>, dispute?: array{choice: string, statements: list<string>, foundOnPages: bool, pasted: bool, pagesChecked: bool}, policyMatch?: array{page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null}} $facts
     *
     * @return array{notes: list<array{key: string, ok: bool, detail?: string}>, unitPriceGaps: list<array{id: string, name: string}>, depositBadLines: list<string>, disputeStatements: list<string>}
     */
    public static function evaluate(array $facts): array
    {
        $notes = [];
        $gaps = $facts['unitPriceGaps'] ?? [];
        if ($gaps !== []) {
            $notes[] = ['key' => 'unitPrices', 'ok' => false];
        }
        $bad = $facts['depositBadLines'] ?? [];
        if ($bad !== []) {
            $notes[] = ['key' => 'depositTypes', 'ok' => false];
        }
        $missing = $facts['missingPolicyPages'] ?? [];
        if ($missing !== []) {
            $notes[] = ['key' => 'checkoutPolicyPages', 'ok' => false, 'detail' => implode(', ', $missing)];
        }
        $statements = [];
        $dispute = $facts['dispute'] ?? null;
        if ($dispute !== null && $dispute['choice'] !== '') {
            $statements = $dispute['statements'];
            if ($statements === []) {
                // "Willing" or "obliged" without the board's name: there is nothing to paste yet.
                $notes[] = ['key' => 'disputeBoard', 'ok' => false];
            } else {
                $placed = $dispute['pasted'] || ($dispute['pagesChecked'] && $dispute['foundOnPages']);
                $notes[] = ['key' => 'disputeStatement', 'ok' => $placed];
            }
        }

        if (isset($facts['policyMatch'])) {
            array_push($notes, ...self::policy($facts['policyMatch']));
        }

        return ['notes' => $notes, 'unitPriceGaps' => $gaps, 'depositBadLines' => $bad, 'disputeStatements' => $statements];
    }

    /**
     * Lines of the withdrawal policy match check. A part that could not be compared is never
     * shown as done: no page, a page with almost no text, a period in months or more than one
     * period, and an address that cannot be compared all ask the merchant to check.
     *
     * @param array{page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null} $policy
     *
     * @return list<array{key: string, ok: bool, detail?: string}>
     */
    private static function policy(array $policy): array
    {
        $result = $policy['result'];
        if (!$policy['page'] || $result === null) {
            return [['key' => 'policyPage', 'ok' => false]];
        }
        if (!$result['checked']) {
            return [['key' => 'policyText', 'ok' => false]];
        }
        $period = $result['period'];
        $days = implode(', ', array_map('strval', $period['found']));
        $lines = [match ($period['status']) {
            PolicyMatch::MATCH => ['key' => 'policyPeriod', 'ok' => true, 'detail' => $days],
            PolicyMatch::DIFFERENT => ['key' => 'policyPeriodDifferent', 'ok' => false, 'detail' => $days],
            PolicyMatch::UNSURE => $period['month']
                ? ['key' => 'policyPeriodMonths', 'ok' => false]
                : ['key' => 'policyPeriodMany', 'ok' => false, 'detail' => $days],
            default => ['key' => 'policyPeriodNone', 'ok' => false],
        }];
        $found = implode(', ', $result['email']['found']);
        $lines[] = match ($result['email']['status']) {
            PolicyMatch::MATCH => ['key' => 'policyEmail', 'ok' => true],
            PolicyMatch::DIFFERENT => ['key' => 'policyEmailDifferent', 'ok' => false, 'detail' => $found],
            PolicyMatch::UNSURE => ['key' => 'policyEmailUnsure', 'ok' => false, 'detail' => $found],
            default => ['key' => 'policyEmailNone', 'ok' => false],
        };

        return $lines;
    }
}
