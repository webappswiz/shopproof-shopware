<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

use Shopproof\Compliance\Rules\RulesFile;

/**
 * Shops established outside the EU (decision 0041): the extra EU duties for sellers from abroad
 * (rules with plugin_feature non_eu_setup), with what Shopproof can see. Same logic as the
 * WooCommerce plugin (Shopproof_Non_EU) and the Shopify app (app/lib/non-eu.js). Returns codes and
 * rule texts only; the admin holds the words. Pure, so it is unit tested alone.
 */
final class NonEuChecklist
{
    /** EU member states plus Iceland, Liechtenstein and Norway (EEA). A shop there is not from abroad. */
    public const EEA = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO'];

    public const FEATURE = 'non_eu_setup';

    /** Free plugin setting: the merchant says the business is established outside the EU and EEA. */
    public const CONFIG = 'ShopproofCompliance.config.establishedOutsideEu';

    /** Shopproof Pro's "where the business is established" setting, when Pro is installed. */
    public const PRO_CONFIG = 'ShopproofPro.config.establishedCountry';

    private function __construct()
    {
    }

    /**
     * Whether the shop is established outside the EU and EEA: the merchant ticked it, or Pro names
     * a country outside them ("OTHER" in Pro means another country, so it counts too). Unknown is no.
     */
    public static function abroad(mixed $ticked, mixed $proCountry): bool
    {
        if ($ticked === true || $ticked === 1 || $ticked === '1') {
            return true;
        }
        $code = \is_string($proCountry) ? strtoupper(trim($proCountry)) : '';
        if ($code === 'OTHER') {
            return true;
        }

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'EU' && !\in_array($code, self::EEA, true);
    }

    /**
     * @param array<string, mixed>|null $file Rules file.
     *
     * @return array{items: list<array{id: string, state: string, requirement: string, shopMust: string, source: string, basis: string}>, open: int, total: int}|null
     */
    public static function build(?array $file, bool $abroad, bool $responsibleSet): ?array
    {
        if (!$abroad) {
            return null;
        }
        $items = [];
        foreach (RulesFile::visible($file) as $rule) {
            if (RulesFile::text($rule, 'plugin_feature') !== self::FEATURE || RulesFile::text($rule, 'jurisdiction') !== 'EU') {
                continue;
            }
            $id = RulesFile::text($rule, 'id');
            // Only the responsible person can be checked here; the rest need a person, never "done".
            $state = $id === 'EU-NE-01' ? ($responsibleSet ? 'good' : 'bad') : 'todo';
            $items[] = [
                'id' => $id,
                'state' => $state,
                'requirement' => RulesFile::text($rule, 'requirement'),
                'shopMust' => RulesFile::text($rule, 'shop_must'),
                'source' => RulesFile::text($rule, 'source_url'),
                'basis' => RulesFile::text($rule, 'legal_basis'),
            ];
        }

        return [
            'items' => $items,
            'open' => \count(array_filter($items, static fn (array $i): bool => $i['state'] !== 'good')),
            'total' => \count($items),
        ];
    }
}
