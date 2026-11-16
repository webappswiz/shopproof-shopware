<?php declare(strict_types=1);

namespace Shopproof\Compliance\Notes;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The shop note settings of a sales channel, read and checked once per request. Every note is off
 * with the defaults, so an update never changes a live shop by itself; food information and
 * deposits show only on products that have values.
 */
class NotesSettings
{
    private const CONFIG = 'ShopproofCompliance.config.';

    /** @var array<string, array{types: array<string, array{name: string, amount: float, kind: string, country: string}>, bad: list<string>}> */
    private array $deposit = [];

    public function __construct(private readonly SystemConfigService $config)
    {
    }

    public function string(string $name, ?string $salesChannelId): string
    {
        return trim($this->config->getString(self::CONFIG . $name, $salesChannelId));
    }

    public function bool(string $name, ?string $salesChannelId): bool
    {
        return $this->config->getBool(self::CONFIG . $name, $salesChannelId);
    }

    /**
     * @return array{types: array<string, array{name: string, amount: float, kind: string, country: string}>, bad: list<string>}
     */
    public function depositTypes(?string $salesChannelId): array
    {
        $key = $salesChannelId ?? '';

        return $this->deposit[$key] ??= Deposit::parseTypes($this->string('depositTypes', $salesChannelId));
    }

    public function depositInPrice(?string $salesChannelId): bool
    {
        return $this->bool('depositInPrice', $salesChannelId);
    }

    /** 'product' (the deposit carries the VAT of its drinks, the default) or 'none'. */
    public function depositTax(?string $salesChannelId): string
    {
        return $this->string('depositTax', $salesChannelId) === 'none' ? 'none' : 'product';
    }

    public function priceTax(?string $salesChannelId): string
    {
        return $this->string('priceNoteTax', $salesChannelId) === 'small' ? 'small' : 'shopware';
    }

    public function priceShipping(?string $salesChannelId): string
    {
        return $this->string('priceNoteShipping', $salesChannelId) === 'free' ? 'free' : 'shopware';
    }

    /**
     * The checkout boxes the shop chose, in the order of the setting.
     *
     * @return list<string>
     */
    public function checkoutBoxes(?string $salesChannelId): array
    {
        $value = $this->config->get(self::CONFIG . 'checkoutBoxes', $salesChannelId);
        $out = [];
        foreach (\is_array($value) ? $value : [] as $key) {
            if (\is_string($key) && \in_array($key, [...CheckoutBoxes::POLICY, ...CheckoutBoxes::CUSTOM], true)) {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, string> custom1..3 => the shop's text
     */
    public function customBoxTexts(?string $salesChannelId): array
    {
        $out = [];
        foreach ([1, 2, 3] as $i) {
            $out['custom' . $i] = $this->string('checkoutBox' . $i, $salesChannelId);
        }

        return $out;
    }

    /**
     * @return array<string, string> custom1..3 => tag name or ''
     */
    public function customBoxTags(?string $salesChannelId): array
    {
        $out = [];
        foreach ([1, 2, 3] as $i) {
            $out['custom' . $i] = $this->string('checkoutBox' . $i . 'Tag', $salesChannelId);
        }

        return $out;
    }

    /**
     * The CMS pages the policy boxes link to: Shopware's own revocation and privacy pages
     * (Settings > Basic information).
     *
     * @return array<string, string>
     */
    public function policyPages(?string $salesChannelId): array
    {
        $pages = [];
        foreach (['withdrawal' => 'core.basicInformation.revocationPage', 'privacy' => 'core.basicInformation.privacyPage'] as $key => $setting) {
            $id = $this->config->get($setting, $salesChannelId);
            $pages[$key] = \is_string($id) && preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $id : '';
        }

        return $pages;
    }

    public function disputeChoice(?string $salesChannelId): string
    {
        $choice = $this->string('disputeChoice', $salesChannelId);

        return \in_array($choice, DisputeStatement::CHOICES, true) ? $choice : '';
    }

    /**
     * The first country chosen in the settings, for text when the page language has none.
     */
    public function shopCountry(?string $salesChannelId): string
    {
        $countries = $this->config->get(self::CONFIG . 'countries', $salesChannelId);

        return \is_array($countries) && isset($countries[0]) && \is_string($countries[0]) ? $countries[0] : '';
    }

    public function reset(): void
    {
        $this->deposit = [];
    }
}
