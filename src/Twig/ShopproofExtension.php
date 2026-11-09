<?php declare(strict_types=1);

namespace Shopproof\Compliance\Twig;

use Shopproof\Compliance\Rules\RulesFile;
use Shopproof\Compliance\Storefront\DurabilityLabel;
use Shopproof\Compliance\Storefront\GuaranteeNotice;
use Shopproof\Compliance\Storefront\HazardLabel;
use Shopproof\Compliance\Storefront\Registrations;
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
        private readonly HazardLabel $hazards,
        private readonly Registrations $registrations,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shopproof_safety', $this->safety(...)),
            new TwigFunction('shopproof_guarantee', $this->guaranteeNotice(...)),
            new TwigFunction('shopproof_durability', $this->durabilityLabel(...), ['is_safe' => ['html']]),
            new TwigFunction('shopproof_once', $this->once(...)),
            new TwigFunction('shopproof_hazard', $this->hazard(...)),
            new TwigFunction('shopproof_safety_layout', $this->safetyLayout(...)),
            new TwigFunction('shopproof_registrations', $this->registrationsBlock(...)),
            new TwigFunction('shopproof_registrations_footer', $this->registrationsFooter(...)),
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
     * Where the product safety block goes: 'buyBox' (default; one line under the Add to cart
     * button that opens to the details, hazard label elements always visible above it) or
     * 'description' (the full block below the product description).
     */
    public function safetyLayout(?string $salesChannelId = null): string
    {
        return $this->config->getString(self::CONFIG . 'safetyPlacement', $salesChannelId) === 'description' ? 'description' : 'buyBox';
    }

    /**
     * The hazard label elements of a product (CLP Art. 48a), or null when it has none. Shown with
     * the product safety block and never folded away.
     *
     * @return array<string, mixed>|null
     */
    public function hazard(mixed $product, ?string $localeCode = null, ?string $salesChannelId = null): ?array
    {
        if (!$product instanceof ProductEntity || !$this->config->getBool(self::CONFIG . 'showSafety', $salesChannelId)) {
            return null;
        }
        $fields = $product->getTranslation('customFields');

        return $this->hazards->forProduct(
            \is_array($fields) ? $fields : null,
            (string) $localeCode,
            self::COUNTRY_LANGUAGE[$this->shopCountry($salesChannelId)] ?? '',
            $this->assetBase('hazards')
        );
    }

    /**
     * The registration numbers and take-back block for a product page, or null. In 'marked' mode
     * (default) only on products marked as electrical equipment or containing batteries; in
     * 'all' mode on every product, with the take-back parts the shop turned on.
     *
     * @return array<string, mixed>|null
     */
    public function registrationsBlock(mixed $product, ?string $localeCode = null, ?string $salesChannelId = null): ?array
    {
        $mode = $this->config->getString(self::CONFIG . 'registrationsProductPage', $salesChannelId) ?: 'marked';
        if (!$product instanceof ProductEntity || !\in_array($mode, ['marked', 'all'], true)) {
            return null;
        }
        $fields = $product->getTranslation('customFields');
        $sections = Registrations::sectionsForProduct(\is_array($fields) ? $fields : null, $mode, [
            'weee' => $this->config->getBool(self::CONFIG . 'takebackWeee', $salesChannelId),
            'batteries' => $this->config->getBool(self::CONFIG . 'takebackBatteries', $salesChannelId),
        ]);
        if ($mode === 'marked' && $sections === []) {
            return null;
        }
        $block = $this->registrations->block(
            Registrations::parseNumbers($this->config->getString(self::CONFIG . 'registrationNumbers', $salesChannelId)),
            $sections,
            $this->config->getBool(self::CONFIG . 'takebackOneForOne', $salesChannelId),
            $this->config->getString(self::CONFIG . 'takebackNote', $salesChannelId),
            (string) $localeCode,
            self::COUNTRY_LANGUAGE[$this->shopCountry($salesChannelId)] ?? ''
        );
        if ($block === null) {
            return null;
        }

        return [...$block, 'symbol' => $this->assetBase('registrations') . 'crossed-out-bin.svg'];
    }

    /**
     * The registration numbers as one line for the footer, or null when off or there are none.
     *
     * @return array<string, mixed>|null
     */
    public function registrationsFooter(?string $localeCode = null, ?string $salesChannelId = null): ?array
    {
        if (!$this->config->getBool(self::CONFIG . 'registrationsFooter', $salesChannelId)) {
            return null;
        }

        return $this->registrations->footer(
            Registrations::parseNumbers($this->config->getString(self::CONFIG . 'registrationNumbers', $salesChannelId)),
            (string) $localeCode,
            self::COUNTRY_LANGUAGE[$this->shopCountry($salesChannelId)] ?? ''
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
        if (!$product instanceof ProductEntity || self::coreLabelShown($product)) {
            return '';
        }
        $fields = $product->getTranslation('customFields');
        $manufacturerName = $product->getManufacturer()?->getTranslation('name');
        $values = DurabilityLabel::values(
            \is_array($fields) ? $fields : null,
            \is_string($manufacturerName) ? $manufacturerName : null,
            $product->getManufacturerNumber()
        );
        return $this->durability->render(
            $values,
            (string) $localeCode,
            self::COUNTRY_LANGUAGE[$this->shopCountry($salesChannelId)] ?? '',
            $this->assetBase('durability')
        );
    }

    /**
     * True when Shopware's own durability label (6.7.14 and later: "Guarantee confirmed" with
     * months, a manufacturer and a manufacturer number) shows in the buy box, so the product never
     * gets two labels.
     */
    public static function coreLabelShown(ProductEntity $product): bool
    {
        // Read as properties, because Shopware before 6.7.14 has neither field.
        $vars = $product->getVars();
        $months = $vars['guaranteeMonths'] ?? null;
        $manufacturer = $product->getManufacturer()?->getTranslation('name') ?? $product->getManufacturer()?->getName();
        $number = $product->getManufacturerNumber();

        return ($vars['guaranteeConfirmed'] ?? false) === true
            && \is_int($months) && $months > 0
            && \is_string($manufacturer) && $manufacturer !== ''
            && \is_string($number) && $number !== '';
    }

    /**
     * URL of a folder of the plugin's storefront files, ending in "/", without a version query.
     */
    private function assetBase(string $folder): string
    {
        $base = $this->packages->getUrl('bundles/shopproofcompliance/storefront/' . $folder . '/', 'asset');

        return (string) preg_replace('/\?.*$/', '', $base);
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
