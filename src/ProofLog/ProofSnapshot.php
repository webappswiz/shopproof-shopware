<?php declare(strict_types=1);

namespace Shopproof\Compliance\ProofLog;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopproof\Compliance\Notes\Deposit;
use Shopproof\Compliance\Notes\PriceHistory;
use Shopproof\Compliance\Placement\PlacementResult;
use Shopproof\Compliance\Rules\RulesFile;
use Shopproof\Compliance\Rules\RulesUpdater;
use Shopproof\Compliance\Storefront\Registrations;
use Shopproof\Compliance\Withdrawal\OrderEmailLine;
use Shopproof\Compliance\Withdrawal\SetupFacts;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The daily snapshot for the proof log: plugin and rules versions, countries, the withdrawal
 * button per storefront, which blocks are on, product counts, the shop's own registration
 * numbers as shown to shoppers, the other feature settings per storefront and the last placement
 * check. Business facts only, no customer data; free text the shop typed is not repeated.
 */
class ProofSnapshot
{
    private const CONFIG = 'ShopproofCompliance.config.';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
        private readonly SetupFacts $facts,
        private readonly RulesUpdater $rules,
        private readonly ProofLog $log,
        private readonly ?PriceHistory $prices = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Sweeps the price history and seals its new rows (decision 0040), records today's snapshot
     * and removes entries past the retention period.
     */
    public function run(): void
    {
        try {
            $this->prices?->daily();
        } catch (\Throwable $e) {
            // The snapshot still goes in; the next day's sweep catches up.
            $this->logger?->error('Shopproof could not sweep or seal the price history: ' . $e->getMessage());
        }
        $this->log->append('snapshot', $this->summary());
        $this->log->prune($this->retentionDays());
    }

    /**
     * Days the log keeps entries, 0 for everything.
     */
    public function retentionDays(): int
    {
        return ProofLog::retentionFrom($this->config->get(self::CONFIG . 'proofLogRetentionDays'));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $facts = $this->facts->collect();
        $rules = $this->rules->current()['file'];
        $version = $this->connection->fetchOne('SELECT version FROM plugin WHERE name = :name', ['name' => 'ShopproofCompliance']);
        $live = ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)];
        $products = $this->count('SELECT COUNT(*) FROM product WHERE version_id = :version AND parent_id IS NULL AND active = 1', $live);
        $withManufacturer = $this->count('SELECT COUNT(*) FROM product WHERE version_id = :version AND parent_id IS NULL AND active = 1 AND product_manufacturer_id IS NOT NULL', $live);
        // Products (a variant counts as its parent) whose pictograms or statements are filled in;
        // a field the merchant cleared is stored as null, "" or [] and does not count.
        $filled = static fn (string $field): string => \sprintf(
            "(JSON_TYPE(JSON_EXTRACT(t.custom_fields, '$.%1\$s')) = 'ARRAY' AND JSON_LENGTH(JSON_EXTRACT(t.custom_fields, '$.%1\$s')) > 0
              OR JSON_TYPE(JSON_EXTRACT(t.custom_fields, '$.%1\$s')) = 'STRING' AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(t.custom_fields, '$.%1\$s'))) <> '')",
            $field
        );
        $hazard = $this->count(
            'SELECT COUNT(DISTINCT COALESCE(p.parent_id, p.id)) FROM product p INNER JOIN product_translation t ON t.product_id = p.id AND t.product_version_id = p.version_id
             WHERE p.version_id = :version AND (' . $filled('shopproof_safety_hazard_pictograms') . ' OR ' . $filled('shopproof_safety_hazard_statements') . ')',
            $live
        );
        $countries = $this->config->get(self::CONFIG . 'countries');

        return [
            'plugin_version' => \is_string($version) ? $version : '',
            'rules_version' => $rules !== null ? RulesFile::text($rules, 'version') : 'none',
            'countries' => \is_array($countries) ? array_values(array_filter($countries, 'is_string')) : [],
            // Per storefront, with the settings each storefront can override, as its shoppers see them.
            'withdrawal_button' => array_map(fn (array $channel): array => [
                'sales_channel' => $channel['name'],
                'active' => $channel['active'],
                'button_on' => $channel['buttonOn'],
                'page_set' => $channel['pageExists'],
                'page_has_form' => $channel['pageHasForm'],
                'order_email_line' => $this->config->getBool(self::CONFIG . 'orderEmailWithdrawalLine', $channel['id']),
                'withdrawal_days' => OrderEmailLine::period($this->config->getInt(self::CONFIG . 'withdrawalDays', $channel['id'])),
                'registrations_footer' => $this->config->getBool(self::CONFIG . 'registrationsFooter', $channel['id']),
                'features' => self::features($this->reader($channel['id'])),
            ], $facts['channels']),
            'withdrawal_flow_active' => $facts['flowActive'],
            'order_email_line' => $this->config->getBool(self::CONFIG . 'orderEmailWithdrawalLine'),
            'withdrawal_days' => OrderEmailLine::period($this->config->getInt(self::CONFIG . 'withdrawalDays')),
            'guarantee_notice' => $facts['guaranteeNotice'],
            'safety_block' => $facts['showSafety'],
            'safety_placement' => $this->config->getString(self::CONFIG . 'safetyPlacement') ?: 'buyBox',
            'products_active' => $products,
            'products_with_manufacturer' => $withManufacturer,
            'manufacturers_without_details' => $facts['manufacturersWithoutDetailsCount'],
            'products_with_hazard_labels' => $hazard,
            // The shop's own registration numbers are business data, recorded as shown to shoppers.
            'registration_numbers' => Registrations::parseNumbers($this->config->getString(self::CONFIG . 'registrationNumbers')),
            'takeback_batteries' => $this->config->getBool(self::CONFIG . 'takebackBatteries'),
            'takeback_weee' => $this->config->getBool(self::CONFIG . 'takebackWeee'),
            'takeback_one_for_one' => $this->config->getBool(self::CONFIG . 'takebackOneForOne'),
            'registrations_product' => $this->config->getString(self::CONFIG . 'registrationsProductPage') ?: 'off',
            'registrations_footer' => $this->config->getBool(self::CONFIG . 'registrationsFooter'),
            'features' => self::features($this->reader(null)),
            'placement_check' => self::placement(PlacementResult::read($this->config->get(PlacementResult::CONFIG))),
        ];
    }

    /**
     * The other shop and shopper facing settings as they take effect: withdrawal options, order
     * email lines, checkout boxes and consent, cancel button, notes and statements. Pure, so it is
     * unit tested alone; `$get` returns a raw setting by its name.
     *
     * @param callable(string): mixed $get
     *
     * @return array<string, mixed>
     */
    public static function features(callable $get): array
    {
        $bool = static fn (string $name): bool => (bool) $get($name);
        $text = static fn (string $name): string => \is_string($get($name)) ? trim($get($name)) : '';
        $boxes = $get('checkoutBoxes');

        return [
            'button_wording' => $text('buttonWording') ?: 'auto',
            'account_link' => $bool('accountWithdrawalLink'),
            'status_emails' => $bool('statusEmails'),
            'item_picker' => $bool('itemPicker'),
            'order_date_field' => $text('withdrawalOrderDate') ?: 'off',
            'exclude_digital' => $bool('excludeDigital'),
            'model_form_on_policy_page' => $bool('modelFormOnPolicyPage'),
            'order_email_legal_links' => $bool('orderEmailLegalLinks'),
            'checkout_boxes' => \is_array($boxes) ? array_values(array_filter($boxes, 'is_string')) : [],
            'cart_descriptions' => $bool('cartDescriptions'),
            'digital_consent' => $bool('digitalConsent'),
            'service_consent' => $text('serviceConsent') ?: 'off',
            'cancel_button' => $bool('cancelButton'),
            'price_note_tax' => $text('priceNoteTax') ?: 'shopware',
            'price_note_shipping' => $text('priceNoteShipping') ?: 'shopware',
            'deposit_types' => \count(Deposit::parseTypes($text('depositTypes'))['types']),
            'deposit_in_price' => $bool('depositInPrice'),
            'food_business_set' => $text('foodBusiness') !== '',
            'dispute_statement' => $text('disputeChoice') ?: 'notSet',
            'dispute_statement_pasted' => $bool('disputePasted'),
            'review_notice' => $text('reviewsChoice') ?: 'notSet',
        ];
    }

    /**
     * The last placement check that ran in the merchant's browser, or null when it never ran.
     *
     * @param array{time: int, pass: int, warn: int, fail: int, skipped: int, linked: int}|null $last
     *
     * @return array{checked_at: string, pass: int, warn: int, fail: int, skipped: int, linked: int}|null
     */
    public static function placement(?array $last): ?array
    {
        if ($last === null) {
            return null;
        }

        return [
            'checked_at' => gmdate('Y-m-d\\TH:i:s\\Z', $last['time']),
            'pass' => $last['pass'],
            'warn' => $last['warn'],
            'fail' => $last['fail'],
            'skipped' => $last['skipped'],
            'linked' => $last['linked'],
        ];
    }

    /**
     * @return callable(string): mixed
     */
    private function reader(?string $salesChannelId): callable
    {
        return fn (string $name): mixed => $this->config->get(self::CONFIG . $name, $salesChannelId);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function count(string $sql, array $params): int
    {
        $value = $this->connection->fetchOne($sql, $params);

        return is_numeric($value) ? (int) $value : 0;
    }
}
