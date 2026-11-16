<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Placement\PlacementResult;
use Shopproof\Compliance\ProofLog\ProofLog;
use Shopproof\Compliance\Rules\RulesFile;
use Shopproof\Compliance\Rules\RulesUpdater;
use Shopproof\Compliance\Setup\Overview;
use Shopproof\Compliance\Storefront\Registrations;
use Shopproof\Compliance\Storefront\SafetyData;
use Shopproof\Compliance\Withdrawal\OrderEmailLine;
use Shopproof\Compliance\Withdrawal\SetupCheck;
use Shopproof\Compliance\Withdrawal\SetupFacts;
use Shopproof\Compliance\Withdrawal\WithdrawalStore;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the top of the Shopproof page: the setup checklist and the status cards
 * (Overview). Reads only the shop's own database; nothing leaves the shop.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class OverviewController extends AbstractController
{
    private const CONFIG = 'ShopproofCompliance.config.';

    public function __construct(
        private readonly SetupFacts $facts,
        private readonly WithdrawalStore $withdrawals,
        private readonly ProofLog $log,
        private readonly RulesUpdater $rules,
        private readonly SystemConfigService $config,
        private readonly Connection $connection,
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/overview',
        name: 'api.action.shopproof.overview',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function overview(Request $request): JsonResponse
    {
        $facts = $this->facts->collect();
        $check = SetupCheck::evaluate($facts);
        ['file' => $file] = $this->rules->current();
        $proofAvailable = $this->log->exists();
        $proof = $proofAvailable ? $this->log->verifyAll() : ['ok' => false, 'checked' => 0];
        $snapshot = $proofAvailable ? $this->log->lastTime('snapshot') : '';
        $snapshotTime = $snapshot !== '' ? strtotime($snapshot . ' UTC') : false;

        return new JsonResponse(Overview::build([
            'check' => $check,
            'placement' => PlacementResult::read($this->config->get(PlacementResult::CONFIG)),
            'manufacturersWithoutDetails' => $facts['manufacturersWithoutDetailsCount'],
            'productsWithoutMaker' => $this->productsWithoutMaker(),
            'products' => $this->products(),
            'registrationNumbers' => \count(Registrations::parseNumbers($this->config->getString(self::CONFIG . 'registrationNumbers'))),
            'withdrawals' => $this->withdrawalCounts(self::zone($request->query->get('timezone'))),
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
        ]));
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
     * Refunds due today, overdue or due within 3 days, over every withdrawal: the refund clock
     * needs each order's delivery and payment state, so the list is read page by page.
     *
     * @return array{total: int, overdue: int, soon: int}
     */
    private function withdrawalCounts(\DateTimeZone $zone): array
    {
        $days = OrderEmailLine::period($this->config->getInt(self::CONFIG . 'withdrawalDays'));
        $out = ['total' => 0, 'overdue' => 0, 'soon' => 0];
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

    private static function zone(mixed $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone(\is_string($name) && \in_array($name, \DateTimeZone::listIdentifiers(), true) ? $name : 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
