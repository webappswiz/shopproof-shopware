<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Shopproof\Compliance\Withdrawal\PolicyMatch;

/**
 * The shop notes part of the setup check: products that look sold by weight or volume but have
 * no unit price, deposit type lines that cannot be read, chosen checkout boxes that cannot show,
 * whether the dispute statement is on the terms and imprint pages, and whether the withdrawal policy
 * (revocation page) names the same period and email as Shopproof and says that customers can also
 * withdraw online (the online withdrawal sentence), for each storefront that sets its
 * own page, period or email (those lines carry the storefront names as "channel"), whether the
 * order button says the order costs money in every storefront language, and whether the
 * delivery and payment line is on. Pure, unit tested alone.
 *
 * None of these changes "ok" of the withdrawal check; each is shown as done or to do. A part that
 * was not checked is never shown as done.
 */
final class NotesCheck
{
    /**
     * @param array{unitPriceGaps?: list<array{id: string, name: string}>, depositBadLines?: list<string>, missingPolicyPages?: list<string>, dispute?: array{choice: string, statements: list<string>, foundOnPages: bool, pasted: bool, pagesChecked: bool}, policyMatch?: array{page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null, online?: array{status: string, lang: string|null, reason: string|null}, sentence?: string, channels?: list<array{names: list<string>, page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null, online?: array{status: string, lang: string|null, reason: string|null}, sentence?: string}>}, orderButton?: array{buttons: list<array{channel: string, locale: string, text: string}>, words: array<string, string>}, deliveryLine?: array{on: bool}, priorPrice?: array{on: bool, onSale: int, missing: list<string>, higher: list<string>, brokenSeals: int, capped?: bool}, legalTexts?: array{provider: string, button: bool}} $facts
     *
     * @return array{notes: list<array{key: string, ok: bool, detail?: string, channel?: string}>, unitPriceGaps: list<array{id: string, name: string}>, depositBadLines: list<string>, disputeStatements: list<string>}
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
            $groups = $facts['policyMatch']['channels'] ?? [];
            if (\count($groups) > 1) {
                // Storefronts with their own revocation page, period or email: one set of lines each.
                foreach ($groups as $group) {
                    $channel = implode(', ', $group['names']);
                    foreach (self::policy($group) as $line) {
                        $notes[] = $channel !== '' ? $line + ['channel' => $channel] : $line;
                    }
                }
            } else {
                array_push($notes, ...self::policy($facts['policyMatch']));
            }
            if (isset($facts['legalTexts'])) {
                $notes = self::legalTexts($notes, $facts['legalTexts']);
            }
        }
        if (isset($facts['priorPrice'])) {
            array_push($notes, ...self::priorPrice($facts['priorPrice']));
        }

        if (isset($facts['orderButton'])) {
            array_push($notes, ...OrderButtonCheck::evaluate($facts['orderButton']['buttons'], $facts['orderButton']['words']));
        }

        if (isset($facts['deliveryLine'])) {
            // On, or a to do: Shopproof cannot read whether the theme already says this.
            $notes[] = ['key' => 'deliveryLine', 'ok' => $facts['deliveryLine']['on']];
        }

        return ['notes' => $notes, 'unitPriceGaps' => $gaps, 'depositBadLines' => $bad, 'disputeStatements' => $statements];
    }

    /**
     * Lines of the 30-day lowest price check (decision 0040). A changed sealed price, a reduced
     * product while the line is off, products with too short a history and crossed-out list prices
     * above the 30-day low are to do; "done" only when products are on sale and none of that applies.
     *
     * @param array{on: bool, onSale: int, missing: list<string>, higher: list<string>, brokenSeals: int, capped?: bool} $prior
     *
     * @return list<array{key: string, ok: bool, detail?: string}>
     */
    public static function priorPrice(array $prior): array
    {
        $lines = [];
        if ($prior['brokenSeals'] > 0) {
            $lines[] = ['key' => 'priorPriceSeals', 'ok' => false, 'detail' => (string) $prior['brokenSeals']];
        }
        if ($prior['onSale'] === 0) {
            return $lines;
        }
        if (!$prior['on']) {
            $lines[] = ['key' => 'priorPriceOff', 'ok' => false, 'detail' => (string) $prior['onSale']];
        } elseif ($prior['missing'] !== []) {
            $lines[] = ['key' => 'priorPriceMissing', 'ok' => false, 'detail' => implode(', ', $prior['missing'])];
        }
        if ($prior['higher'] !== []) {
            $lines[] = ['key' => 'priorPriceHigher', 'ok' => false, 'detail' => implode(', ', $prior['higher'])];
        }
        if ($lines === [] && ($prior['capped'] ?? false)) {
            // Only the first products on sale were checked: never "done" for the whole shop.
            $lines[] = ['key' => 'priorPriceCapped', 'ok' => false, 'detail' => (string) $prior['onSale']];
        } elseif ($lines === []) {
            $lines[] = ['key' => 'priorPrice', 'ok' => true];
        }

        return $lines;
    }

    /**
     * A legal text provider's plugin fills the revocation page (decision 0048). A page with almost
     * no text says the plugin may load its text only when the page is shown (still not checked);
     * a page that was read gets one line that names the provider and says to fix a difference in
     * the Shopproof settings or with the provider, never on the page. A second withdrawal button
     * from the provider's plugin is a to do.
     *
     * @param list<array{key: string, ok: bool, detail?: string, channel?: string}> $notes
     * @param array{provider: string, button: bool}                                $legal
     *
     * @return list<array{key: string, ok: bool, detail?: string, channel?: string}>
     */
    private static function legalTexts(array $notes, array $legal): array
    {
        $read = false;
        foreach ($notes as $i => $note) {
            if ($note['key'] === 'policyText') {
                $notes[$i] = ['key' => 'policyTextProvider', 'ok' => false, 'detail' => $legal['provider']] + $note;
            } elseif (str_starts_with($note['key'], 'policyPeriod')) {
                $read = true;
            }
        }
        if ($read) {
            $notes[] = ['key' => 'policyProvider', 'ok' => true, 'detail' => $legal['provider']];
        }
        if ($legal['button']) {
            $notes[] = ['key' => 'providerButton', 'ok' => false, 'detail' => $legal['provider']];
        }

        return $notes;
    }

    /**
     * Lines of the withdrawal policy match check. A part that could not be compared is never
     * shown as done: no page, a page with almost no text, a period in months or more than one
     * period, and an address that cannot be compared all ask the merchant to check.
     *
     * @param array{page: bool, result: array{checked: bool, period: array{status: string, found: list<int>, month: bool}, email: array{status: string, found: list<string>}}|null, online?: array{status: string, lang: string|null, reason: string|null}, sentence?: string} $policy
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
        // The online withdrawal sentence (rules EU-WD-02, DE-WD-02, AT-WD-02): found, not found in a
        // policy the check can read, or not checked for its language. Never "done" unless found.
        if (isset($policy['online'])) {
            $sentence = $policy['sentence'] ?? '';
            $lines[] = match ($policy['online']['status']) {
                PolicyMatch::FOUND => ['key' => 'policyOnline', 'ok' => true],
                PolicyMatch::NOT_FOUND => ['key' => 'policyOnlineNone', 'ok' => false, 'detail' => $sentence],
                default => ['key' => 'policyOnlineUnsure', 'ok' => false, 'detail' => $sentence],
            };
        }

        return $lines;
    }
}
