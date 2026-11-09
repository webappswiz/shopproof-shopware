<?php declare(strict_types=1);

namespace Shopproof\Compliance\Twig;

use Shopproof\Compliance\Rules\RulesFile;
use Shopproof\Compliance\Storefront\DurabilityLabel;
use Shopproof\Compliance\Storefront\GuaranteeNotice;
use Shopproof\Compliance\Storefront\SafetyData;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Asset\Packages;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Storefront helpers for the product page blocks. The templates render everything on the server,
 * so the blocks need no JavaScript and take the theme's own styles.
 */
final class ShopproofExtension extends AbstractExtension implements ResetInterface
{
    private const CONFIG = 'ShopproofCompliance.config.';

    /** Setting name for each party field (plugin settings use camelCase names). */
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

    /** Language of each launch country, for text when the page language has none. */
    private const COUNTRY_LANGUAGE = ['AT' => 'de', 'DE' => 'de', 'FR' => 'fr', 'IT' => 'it', 'NL' => 'nl'];

    /** @var array<string, true> blocks already shown on this page */
    private array $shown = [];

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly GuaranteeNotice $guarantee,
        private readonly DurabilityLabel $durability,
        private readonly Packages $packages,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shopproof_safety', $this->safety(...)),
            new TwigFunction('shopproof_guarantee', $this->guaranteeNotice(...)),
            new TwigFunction('shopproof_durability', $this->durabilityLabel(...), ['is_safe' => ['html']]),
            new TwigFunction('shopproof_once', $this->once(...)),
        ];
    }

    /**
     * True the first time a block is asked for on a page, false after. A block can sit in two
     * places (the product description and the Shopproof page designer element, or the buy box and
     * that element), and the shopper must see it only once.
     */
    public function once(string $key): bool
    {
        if (isset($this->shown[$key])) {
            return false;
        }
        $this->shown[$key] = true;

        return true;
    }

    public function reset(): void
    {
        $this->shown = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function safety(mixed $product, ?string $salesChannelId = null): ?array
    {
        if (!$product instanceof ProductEntity || !$this->config->getBool(self::CONFIG . 'showSafety', $salesChannelId)) {
            return null;
        }
        $defaults = [];
        foreach (self::SETTING as $field => $setting) {
            $defaults[$field] = $this->config->getString(self::CONFIG . $setting, $salesChannelId);
        }
        $manufacturer = $product->getManufacturer();
        $productFields = $product->getTranslation('customFields');
        $manufacturerName = $manufacturer?->getTranslation('name');
        $manufacturerFields = $manufacturer?->getTranslation('customFields');

        return SafetyData::resolve(
            \is_array($productFields) ? $productFields : null,
            \is_string($manufacturerName) ? $manufacturerName : null,
            \is_array($manufacturerFields) ? $manufacturerFields : null,
            $defaults
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function guaranteeNotice(?string $localeCode = null, ?string $salesChannelId = null): ?array
    {
        if (!$this->config->getBool(self::CONFIG . 'showGuaranteeNotice', $salesChannelId)) {
            return null;
        }
        return $this->guarantee->forPage((string) $localeCode, $this->shopCountry($salesChannelId));
    }

    /**
     * The durability label for a product, as escaped HTML, or '' when the product has no valid
     * label (no guarantee of more than two years, or no brand or model).
     */
    public function durabilityLabel(mixed $product, ?string $localeCode = null, ?string $salesChannelId = null): string
    {
        if (!$product instanceof ProductEntity) {
            return '';
        }
        $fields = $product->getTranslation('customFields');
        $manufacturerName = $product->getManufacturer()?->getTranslation('name');
        $values = DurabilityLabel::values(
            \is_array($fields) ? $fields : null,
            \is_string($manufacturerName) ? $manufacturerName : null,
            $product->getManufacturerNumber()
        );
        $base = $this->packages->getUrl('bundles/shopproofcompliance/storefront/durability/', 'asset');
        $base = (string) preg_replace('/\?.*$/', '', $base);

        return $this->durability->render(
            $values,
            (string) $localeCode,
            self::COUNTRY_LANGUAGE[$this->shopCountry($salesChannelId)] ?? '',
            $base
        );
    }

    /**
     * The first country chosen in the settings, or '' when none is usable.
     */
    private function shopCountry(?string $salesChannelId): string
    {
        $countries = $this->config->get(self::CONFIG . 'countries', $salesChannelId);
        $first = \is_array($countries) && isset($countries[0]) && \is_string($countries[0]) ? $countries[0] : '';

        return \in_array($first, RulesFile::LAUNCH_COUNTRIES, true) ? $first : '';
    }
}
