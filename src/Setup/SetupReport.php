<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

/**
 * Turns the SetupCheck result into plain text lines and an exit code for the command line, so
 * agencies can check many shops from a script. Pure, unit tested alone.
 *
 * Exit codes: 0 everything checked is set up, 1 something needs work, 2 a part could not be
 * checked. A part that did not run is never reported as fine.
 */
final class SetupReport
{
    public const OK = 0;

    public const NEEDS_WORK = 1;

    public const NOT_CHECKED = 2;

    private const SHOP = [
        'flowActive' => 'The flow "Online revocation request sent" is active, so emails go out',
        'customerMail' => 'The flow emails the shopper a confirmation with the request and its date and time',
        'recordTable' => 'Shopproof\'s table for the records exists (shopproof_withdrawal_request). If not, reinstall the plugin.',
    ];

    private const CHANNEL = [
        'buttonOn' => 'The withdrawal button is switched on',
        'page' => 'The button links to a withdrawal request page',
        'form' => 'That page has the withdrawal request form',
        'labels' => 'Every storefront language has the national button wording',
        'wording' => 'The chosen button wording applies in every storefront language',
        'returnsUrl' => 'Your returns tool address can be used',
    ];

    private const PRODUCTS = [
        'guaranteeNotice' => ['The EU legal guarantee notice is shown on product pages', 'The EU legal guarantee notice is off in at least one storefront. It is required for goods from 27 September 2026.'],
        'manufacturerDetails' => ['Every manufacturer of your products has contact details for the product safety information', 'These manufacturers have no contact details, so their products show the manufacturer\'s name only:'],
        'hazardStatements' => ['', 'These products have hazard or precautionary statements the product page cannot show:'],
        'registrationNumbers' => ['', 'These lines of "Your registration numbers" are not shown (no colon, no number, or more than 12):'],
    ];

    private const PARTS = [
        'durability' => 'the durability label',
        'safety' => 'product safety',
        'guarantee' => 'the guarantee notice and take-back information',
    ];

    /**
     * Report for a check that could not run at all, for example when the database could not be read.
     *
     * @return array{code: int, lines: list<string>}
     */
    public static function notChecked(string $reason): array
    {
        return ['code' => self::NOT_CHECKED, 'lines' => [
            'Shopproof setup check',
            '[not checked] The check could not run: ' . $reason,
            '',
            'Technical help, not legal advice.',
        ]];
    }

    /**
     * @param array{supported: bool, ok: bool, shop: list<array{key: string, ok: bool}>, channels: list<array{id: string, name: string, ok: bool, checks: list<array{key: string, ok: bool, detail?: string}>}>, layouts: list<array{id: string, name: string, ok: bool, missing: list<string>}>, failedRecords: int, products: list<array{key: string, ok: bool}>, manufacturersWithoutDetails: list<array{id: string, name: string}>, hazardProblems: list<array{id: string, name: string, codes: list<array{code: string, reason: string}>}>, registrationBadLines: list<string>} $result
     *
     * @return array{code: int, lines: list<string>}
     */
    public static function render(array $result, string $shop = '', int $manufacturerCount = 0): array
    {
        $lines = ['Shopproof setup check' . ($shop !== '' ? ': ' . $shop : '')];
        $needsWork = false;
        $notChecked = false;

        $lines[] = '';
        $lines[] = 'Withdrawal button';
        if (!$result['supported']) {
            $notChecked = true;
            $lines[] = '[not checked] This Shopware version has no withdrawal button yet. Update to Shopware 6.7.9 or later to use it.';
        } else {
            foreach ($result['shop'] as $check) {
                $lines[] = self::mark($check['ok']) . ' ' . (self::SHOP[$check['key']] ?? $check['key']);
                $needsWork = $needsWork || !$check['ok'];
            }
            if ($result['channels'] === []) {
                $needsWork = true;
                $lines[] = '[to do] No active storefront sales channel was found.';
            }
            foreach ($result['channels'] as $channel) {
                $lines[] = 'Sales channel "' . $channel['name'] . '":';
                foreach ($channel['checks'] as $check) {
                    $text = self::CHANNEL[$check['key']] ?? $check['key'];
                    $detail = $check['detail'] ?? '';
                    if (!$check['ok'] && $detail !== '') {
                        $text .= ' (missing: ' . $detail . ')';
                    }
                    if (!$check['ok'] && $check['key'] === 'wording') {
                        // The national wording still applies, so this is a warning, not a to do.
                        $lines[] = '  [check] ' . $text . '. Until then the national wording applies.';
                        $needsWork = true;
                        continue;
                    }
                    if (!$check['ok'] && $check['key'] === 'returnsUrl') {
                        // Withdrawals are still recorded, so this is a warning, not a to do.
                        $lines[] = '  [check] Your returns tool address cannot be used: it is not https on the internet, so withdrawals are not sent on. They are still recorded.';
                        $needsWork = true;
                        continue;
                    }
                    $lines[] = '  ' . self::mark($check['ok']) . ' ' . $text;
                    $needsWork = $needsWork || !$check['ok'];
                }
            }
        }
        if ($result['failedRecords'] > 0) {
            $needsWork = true;
            $lines[] = \sprintf(
                '[to do] %d withdrawal %s could not be recorded. Check the shop\'s emails and var/log (search for "Shopproof could not record"), then mark them as checked on the Shopproof page.',
                $result['failedRecords'],
                $result['failedRecords'] === 1 ? 'request' : 'requests'
            );
        }

        $lines[] = '';
        $lines[] = 'Product pages';
        if ($result['layouts'] === []) {
            $notChecked = true;
            $lines[] = '[not checked] No product page layout was found.';
        }
        foreach ($result['layouts'] as $layout) {
            if ($layout['ok']) {
                $lines[] = '[ok] Layout "' . $layout['name'] . '" shows the Shopproof blocks';
                continue;
            }
            $needsWork = true;
            $parts = array_map(static fn (string $part): string => self::PARTS[$part] ?? $part, $layout['missing']);
            $lines[] = '[to do] Layout "' . $layout['name'] . '" has no place for ' . implode(', ', $parts) . '. Add the block "Shopproof product information" (category Commerce).';
        }
        foreach ($result['products'] as $check) {
            [$okText, $offText] = self::PRODUCTS[$check['key']] ?? [$check['key'], $check['key']];
            if ($check['ok']) {
                $lines[] = '[ok] ' . $okText;
                continue;
            }
            $needsWork = true;
            $lines[] = '[check] ' . $offText;
            $details = self::details($check['key'], $result);
            foreach ($details as $detail) {
                $lines[] = '  - ' . $detail;
            }
            if ($check['key'] === 'manufacturerDetails' && $manufacturerCount > \count($details)) {
                $lines[] = \sprintf('  - and %d more', $manufacturerCount - \count($details));
            }
        }

        $code = $notChecked ? self::NOT_CHECKED : ($needsWork ? self::NEEDS_WORK : self::OK);
        $lines[] = '';
        $lines[] = match ($code) {
            self::OK => 'Result: everything checked is set up.',
            self::NEEDS_WORK => 'Result: some things need work (see [no], [to do] and [check] above).',
            default => 'Result: not everything could be checked (see [not checked] above).',
        };
        $lines[] = 'Technical help, not legal advice. A person still needs to check the texts and the pages.';

        return ['code' => $code, 'lines' => $lines];
    }

    private static function mark(bool $ok): string
    {
        return $ok ? '[ok]' : '[no]';
    }

    /**
     * @param array{manufacturersWithoutDetails: list<array{id: string, name: string}>, hazardProblems: list<array{id: string, name: string, codes: list<array{code: string, reason: string}>}>, registrationBadLines: list<string>} $result
     *
     * @return list<string>
     */
    private static function details(string $key, array $result): array
    {
        return match ($key) {
            'manufacturerDetails' => array_map(static fn (array $m): string => $m['name'], $result['manufacturersWithoutDetails']),
            'hazardStatements' => array_map(
                static fn (array $p): string => $p['name'] . ': ' . implode(', ', array_map(
                    static fn (array $c): string => $c['code'] . ($c['reason'] === 'own' ? ' (needs your own words)' : ' (unknown code)'),
                    $p['codes']
                )),
                $result['hazardProblems']
            ),
            'registrationNumbers' => $result['registrationBadLines'],
            default => [],
        };
    }
}
