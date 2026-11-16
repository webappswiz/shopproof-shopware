<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

/**
 * The shop notes part of the setup check: products that look sold by weight or volume but have
 * no unit price, deposit type lines that cannot be read, chosen checkout boxes that cannot show,
 * and whether the dispute statement is on the terms and imprint pages. Pure, unit tested alone.
 *
 * None of these changes "ok" of the withdrawal check; each is shown as done or to do. A part that
 * was not checked is never shown as done.
 */
final class NotesCheck
{
    /**
     * @param array{unitPriceGaps?: list<array{id: string, name: string}>, depositBadLines?: list<string>, missingPolicyPages?: list<string>, dispute?: array{choice: string, statements: list<string>, foundOnPages: bool, pasted: bool, pagesChecked: bool}} $facts
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

        return ['notes' => $notes, 'unitPriceGaps' => $gaps, 'depositBadLines' => $bad, 'disputeStatements' => $statements];
    }
}
