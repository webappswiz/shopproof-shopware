<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Marketplace\MarketplaceExport;
use Shopproof\Compliance\Storefront\SafetyData;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the product safety export to eBay and Amazon (decision 0046), as in the
 * WooCommerce plugin and the Shopify app. The safety details come from the same place as the
 * product page block (SafetyData::resolve). The eBay file the merchant chose is read as text and
 * handed back filled in; nothing is stored and nothing leaves the shop.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class MarketplaceController extends AbstractController
{
    /** Largest eBay file read, the same limit as the WooCommerce plugin. */
    public const MAX_SIZE = 8 * 1024 * 1024;

    /** Products read per database query. */
    private const BATCH = 250;

    private const CONFIG = 'ShopproofCompliance.config.';

    /** The shop-wide default parties, by party field. */
    private const SETTING = [
        'manufacturer_name' => 'safetyManufacturerName',
        'manufacturer_address' => 'safetyManufacturerAddress',
        'manufacturer_email' => 'safetyManufacturerEmail',
        'manufacturer_url' => 'safetyManufacturerUrl',
        'responsible_name' => 'safetyResponsibleName',
        'responsible_address' => 'safetyResponsibleAddress',
        'responsible_email' => 'safetyResponsibleEmail',
        'responsible_url' => 'safetyResponsibleUrl',
    ];

    /**
     * @param EntityRepository<\Shopware\Core\Content\Product\ProductCollection> $products
     */
    public function __construct(
        private readonly EntityRepository $products,
        private readonly SystemConfigService $config,
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/marketplace/ebay',
        name: 'api.action.shopproof.marketplace.ebay',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['product:read']],
        methods: ['POST']
    )]
    public function ebay(Request $request, Context $context): JsonResponse
    {
        $csv = $request->request->get('csv');
        if (!\is_string($csv) || trim($csv) === '') {
            return new JsonResponse(['error' => 'no-file']);
        }
        if (\strlen($csv) > self::MAX_SIZE) {
            return new JsonResponse(['error' => 'too-big']);
        }
        // First pass only collects the SKUs, so the products are read in a few queries.
        $skus = [];
        MarketplaceExport::ebayMerge($csv, static function (string $sku) use (&$skus) {
            $skus[$sku] = true;

            return null;
        });
        $found = $this->bySku(array_map('strval', array_keys($skus)), $context);

        return new JsonResponse(MarketplaceExport::ebayMerge($csv, static fn (string $sku) => $found[$sku] ?? null));
    }

    #[Route(
        path: '/api/_action/shopproof/marketplace/amazon',
        name: 'api.action.shopproof.marketplace.amazon',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['product:read']],
        methods: ['POST']
    )]
    public function amazon(Context $context): JsonResponse
    {
        $criteria = $this->criteria();
        $criteria->addFilter(new EqualsFilter('active', true), new EqualsFilter('childCount', 0));
        $criteria->addSorting(new FieldSorting('productNumber'));
        $defaults = $this->defaults();
        $list = [];
        $context->enableInheritance(function (Context $context) use ($criteria, $defaults, &$list): void {
            $iterator = new RepositoryIterator($this->products, $context, $criteria);
            while (($result = $iterator->fetch()) !== null) {
                foreach ($result->getEntities() as $product) {
                    $list[] = ['sku' => $product->getProductNumber()] + self::parties($product, $defaults);
                }
            }
        });

        // Shopware gives every product a product number, so none is left out for want of one.
        return new JsonResponse(MarketplaceExport::amazonFiles($list) + ['noSku' => 0]);
    }

    /**
     * The safety details of the products with these product numbers, by product number.
     *
     * @param list<string> $skus
     *
     * @return array<string, array{manufacturer: array{name: string, address: string, email: string, url: string}|null, responsible: array{name: string, address: string, email: string, url: string}|null}>
     */
    private function bySku(array $skus, Context $context): array
    {
        $skus = array_values(array_unique(array_filter($skus, static fn (string $s) => $s !== '')));
        if ($skus === []) {
            return [];
        }
        $criteria = $this->criteria();
        $criteria->addFilter(new EqualsAnyFilter('productNumber', $skus));
        $defaults = $this->defaults();
        $out = [];
        // One search, read in pages of BATCH products (no repository call in a loop).
        $context->enableInheritance(function (Context $context) use ($criteria, $defaults, &$out): void {
            $iterator = new RepositoryIterator($this->products, $context, $criteria);
            while (($result = $iterator->fetch()) !== null) {
                foreach ($result->getEntities() as $product) {
                    $out[$product->getProductNumber()] = self::parties($product, $defaults);
                }
            }
        });

        return $out;
    }

    private function criteria(): Criteria
    {
        $criteria = new Criteria();
        $criteria->setLimit(self::BATCH);
        $criteria->addAssociation('manufacturer');

        return $criteria;
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        $out = [];
        foreach (self::SETTING as $field => $setting) {
            $out[$field] = $this->config->getString(self::CONFIG . $setting);
        }

        return $out;
    }

    /**
     * The manufacturer and EU responsible person of a product, as on its product page.
     *
     * @param array<string, string> $defaults
     *
     * @return array{manufacturer: array{name: string, address: string, email: string, url: string}|null, responsible: array{name: string, address: string, email: string, url: string}|null}
     */
    private static function parties(ProductEntity $product, array $defaults): array
    {
        $manufacturer = $product->getManufacturer();
        $productFields = $product->getTranslation('customFields');
        $manufacturerName = $manufacturer?->getTranslation('name');
        $manufacturerFields = $manufacturer?->getTranslation('customFields');
        $data = SafetyData::resolve(
            \is_array($productFields) ? $productFields : null,
            \is_string($manufacturerName) ? $manufacturerName : null,
            \is_array($manufacturerFields) ? $manufacturerFields : null,
            $defaults
        );

        return ['manufacturer' => $data['manufacturer'] ?? null, 'responsible' => $data['responsiblePerson'] ?? null];
    }
}
