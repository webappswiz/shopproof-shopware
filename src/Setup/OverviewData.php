<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Controller\RulesController;
use Shopproof\Compliance\Placement\PlacementResult;
use Shopproof\Compliance\ProofLog\ProofLog;
use Shopproof\Compliance\Rules\RulesFile;
use Shopproof\Compliance\Rules\RulesUpdater;
use Shopproof\Compliance\Storefront\Registrations;
use Shopproof\Compliance\Storefront\SafetyData;
use Shopproof\Compliance\Withdrawal\OrderEmailLine;
use Shopproof\Compliance\Withdrawal\SetupCheck;
use Shopproof\Compliance\Withdrawal\SetupFacts;
use Shopproof\Compliance\Withdrawal\WithdrawalStore;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The data behind the top of the Shopproof page (setup checklist, status cards, the one answer,
 * the non-EU checklist and "Switch on what your shop needs"). Used by the admin page and the AI
 * assistant (decision 0047), so both always agree. Reads only the shop's own database.
 */
final class OverviewData
{
    private const CONFIG = 'ShopproofCompliance.config.';

    public function __construct(
        private readonly SetupFacts $facts,
        private readonly WithdrawalStore $withdrawals,
        private readonly ProofLog $log,
        private readonly RulesUpdater $rules,
        private readonly SystemConfigService $config,
        private readonly Connection $connection,
        private readonly Recommended $recommended,
    ) {
    }

    /**
     * With $report, also the setup check as plain text lines (as `shopproof:setup:check` prints
     * them) under "report".
     *
     * @return array<string, mixed>
     */
    public function build(\DateTimeZone $zone, bool $report = false): array
    {
        $facts = $this->facts->collect();
        $check = SetupCheck::evaluate($facts);
        ['file' => $file] = $this->rules->current();
        $proofAvailable = $this->log->exists();
        $proof = $proofAvailable ? $this->log->verifyAll() : ['ok' => false, 'checked' => 0];
        $snapshot = $proofAvailable ? $this->log->lastTime('snapshot') : '';
        $snapshotTime = $snapshot !== '' ? strtotime($snapshot . ' UTC') : false;

        $out = Overview::build([
            'check' => $check,
            'placement' => PlacementResult::read($this->config->get(PlacementResult::CONFIG)),
            'manufacturersWithoutDetails' => $facts['manufacturersWithoutDetailsCount'],
            'productsWithoutMaker' => $this->productsWithoutMaker(),
            'products' => $this->products(),
            'registrationNumbers' => \count(Registrations::parseNumbers($this->config->getString(self::CONFIG . 'registrationNumbers'))),
            'withdrawals' => $this->withdrawalCounts($zone),
            'proof' => [
                'available' => $proofAvailable,
                'ok' => $proof['ok'],
                'checked' => $proof['checked'],
                'lastSnapshot' => $snapshotTime === false ? null : $snapshotTime,
            ],
            'rules' => [
                'available' => $file !== null,
                'count' => \count(RulesFile::forCountries($file, RulesController::chosenCountries($this->config->get(self::CONFIG . 'countries')))),
                'version' => RulesFile::text($file, 'version'),
            ],
            'now' => time(),
        ]);
        // Shops established outside the EU get the extra duties for sellers from abroad (decision 0041).
        $out['nonEu'] = NonEuChecklist::build(
            $file,
            NonEuChecklist::abroad($this->config->get(NonEuChecklist::CONFIG), $this->config->get(NonEuChecklist::PRO_CONFIG)),
            trim($this->config->getString(self::CONFIG . 'safetyResponsibleName')) !== ''
                && trim($this->config->getString(self::CONFIG . 'safetyResponsibleAddress')) !== ''
        );

        // "Switch on what your shop needs": what one press would switch on, and the last press while it can be undone.
        $out['recommended'] = [
            'plan' => Recommended::plan($this->recommended->facts()),
            'last' => $this->recommended->last(time()),
        ];

        if ($report) {
            $out['report'] = SetupReport::render($check, '', $facts['manufacturersWithoutDetailsCount']);
        }

        return $out;
    }

    private function products(): int
    {
        return self::count($this->connection->fetchOne(
            'SELECT COUNT(*) FROM product p WHERE p.version_id = :version AND p.parent_id IS NULL AND p.active = 1',
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        ));
    }

    /**
     * Active products with no Shopware manufacturer and no manufacturer name of their own, so their
     * page names nobody. None while the shop-wide default manufacturer is set.
     */
    private function productsWithoutMaker(): int
    {
        if (trim($this->config->getString(self::CONFIG . 'safetyManufacturerName')) !== '') {
            return 0;
        }

        return self::count($this->connection->fetchOne(
            'SELECT COUNT(*) FROM product p
             LEFT JOIN product_translation pt ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = :language
             WHERE p.version_id = :version AND p.parent_id IS NULL AND p.active = 1 AND p.product_manufacturer_id IS NULL
               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(pt.custom_fields, :path)), \'\') = \'\'',
            [
                'language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'path' => '$.' . SafetyData::PREFIX . 'manufacturer_name',
            ]
        ));
    }

    /**
     * Refunds due today, overdue or due within 3 days, and the others still waiting for a refund,
     * over every withdrawal: the refund clock needs each order's delivery and payment state, so the
     * list is read page by page. A withdrawal sent late or usually without a right runs no refund
     * clock and is not counted; the list shows it with its own badge.
     *
     * @return array{total: int, overdue: int, soon: int, open: int}
     */
    private function withdrawalCounts(\DateTimeZone $zone): array
    {
        $days = OrderEmailLine::period($this->config->getInt(self::CONFIG . 'withdrawalDays'));
        $out = ['total' => 0, 'overdue' => 0, 'soon' => 0, 'open' => 0];
        $seen = 0;
        for ($page = 1; ; ++$page) {
            $list = $this->withdrawals->list($page, 100, '', $zone, $days);
            $out['total'] = $list['total'];
            foreach ($list['items'] as $item) {
                $clock = \is_array($item['clock'] ?? null) ? $item['clock'] : null;
                $refund = $clock !== null ? ($clock['refund'] ?? '') : '';
                if ($refund === 'today' || $refund === 'overdue') {
                    ++$out['overdue'];
                } elseif ($refund === 'due' && \is_int($clock['daysLeft'] ?? null) && $clock['daysLeft'] <= 3) {
                    ++$out['soon'];
                } elseif ($refund === 'due' || $refund === 'waiting') {
                    ++$out['open'];
                }
            }
            $seen += \count($list['items']);
            if (\count($list['items']) < 100 || $seen >= $list['total']) {
                break;
            }
        }

        return $out;
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

}
