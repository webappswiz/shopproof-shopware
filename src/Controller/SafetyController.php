<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Setup\ShopDetails;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ready-made product safety details (as the Shopify app's safety profile prefill): the shop's own
 * name, address, email and website from Settings > Basic information, offered as the default
 * manufacturer for shops that make their own goods. Nothing is saved until the merchant clicks,
 * and a click fills only the default fields that are still empty.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class SafetyController extends AbstractController
{
    /** The default manufacturer settings, by detail. */
    public const FIELDS = [
        'name' => 'ShopproofCompliance.config.safetyManufacturerName',
        'address' => 'ShopproofCompliance.config.safetyManufacturerAddress',
        'email' => 'ShopproofCompliance.config.safetyManufacturerEmail',
        'url' => 'ShopproofCompliance.config.safetyManufacturerUrl',
    ];

    public function __construct(
        private readonly ShopDetails $shop,
        private readonly SystemConfigService $config,
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/safety/shop-details',
        name: 'api.action.shopproof.safety.shop_details',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function details(): JsonResponse
    {
        return new JsonResponse(ShopDetails::offer($this->suggestion(), $this->current()));
    }

    #[Route(
        path: '/api/_action/shopproof/safety/use-shop-details',
        name: 'api.action.shopproof.safety.use_shop_details',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function use(): JsonResponse
    {
        $offer = ShopDetails::offer($this->suggestion(), $this->current());
        foreach ($offer['fills'] as $key) {
            $this->config->set(self::FIELDS[$key], $offer['shop'][$key]);
        }

        return new JsonResponse(ShopDetails::offer($this->suggestion(), $this->current()) + ['filled' => $offer['fills']]);
    }

    /**
     * @return array{name: string, address: string, email: string, url: string}
     */
    private function suggestion(): array
    {
        $shop = $this->shop->get(null);

        return ['name' => $shop['name'], 'address' => ShopDetails::postal($shop), 'email' => $shop['email'], 'url' => $shop['url']];
    }

    /**
     * @return array{name: string, address: string, email: string, url: string}
     */
    private function current(): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $setting) {
            $out[$key] = trim($this->config->getString($setting));
        }

        return ['name' => $out['name'], 'address' => $out['address'], 'email' => $out['email'], 'url' => $out['url']];
    }
}
