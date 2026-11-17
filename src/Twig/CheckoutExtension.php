<?php declare(strict_types=1);

namespace Shopproof\Compliance\Twig;

use Shopproof\Compliance\Checkout\DigitalConsent;
use Shopproof\Compliance\Checkout\ServiceWithdrawal;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Checkout helpers: whether the two digital content boxes replace Shopware's one box, and whether
 * the two early start boxes for services are shown (and required).
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
            new TwigFunction('shopproof_service_consent', $this->serviceConsent(...)),
        ];
    }

    public function digitalConsent(?SalesChannelContext $context): bool
    {
        return $context !== null && $this->config->getBool(DigitalConsent::SETTING, $context->getSalesChannelId());
    }

    /**
     * '' (no boxes), optional or required, for a cart with a service when the setting is on.
     */
    public function serviceConsent(?Cart $cart, ?SalesChannelContext $context): string
    {
        if ($cart === null || $context === null) {
            return '';
        }
        $mode = ServiceWithdrawal::mode($this->config->get(ServiceWithdrawal::SETTING, $context->getSalesChannelId()));

        return $mode !== '' && ServiceWithdrawal::hasService($cart->getLineItems()->getFlat()) ? $mode : '';
    }
}
