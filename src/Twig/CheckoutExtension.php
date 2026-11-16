<?php declare(strict_types=1);

namespace Shopproof\Compliance\Twig;

use Shopproof\Compliance\Checkout\DigitalConsent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Checkout helpers: whether the two digital content boxes replace Shopware's one box.
 */
final class CheckoutExtension extends AbstractExtension
{
    public function __construct(private readonly SystemConfigService $config)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shopproof_digital_consent', $this->digitalConsent(...)),
        ];
    }

    public function digitalConsent(?SalesChannelContext $context): bool
    {
        return $context !== null && $this->config->getBool(DigitalConsent::SETTING, $context->getSalesChannelId());
    }
}
