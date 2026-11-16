<?php declare(strict_types=1);

namespace Shopproof\Compliance\Twig;

use Shopproof\Compliance\Withdrawal\ModelForm;
use Shopproof\Compliance\Setup\ShopDetails;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Puts the EU model withdrawal form at the end of the withdrawal policy page, the page set as
 * "Revocation page" under Settings > Basic information, wherever Shopware shows it (its own page,
 * the category that shows it, and the pop-up at checkout). Only when the merchant turns it on:
 * many legal text services already put the form on that page.
 */
final class ModelFormExtension extends AbstractExtension
{
    public const SETTING = 'ShopproofCompliance.config.modelFormOnPolicyPage';
    public const POLICY_PAGE = 'core.basicInformation.revocationPage';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly ModelForm $form,
        private readonly ShopDetails $shop,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shopproof_model_form', $this->render(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * The form's HTML for a CMS page, or '' unless it is the withdrawal policy page and the
     * setting is on.
     */
    public function render(mixed $cmsPageId, ?SalesChannelContext $context): string
    {
        if ($context === null || !\is_string($cmsPageId) || $cmsPageId === '') {
            return '';
        }
        $channel = $context->getSalesChannelId();
        $policy = $this->config->get(self::POLICY_PAGE, $channel);
        if (!$this->config->getBool(self::SETTING, $channel) || !\is_string($policy) || strtolower($policy) !== strtolower($cmsPageId)) {
            return '';
        }

        return $this->form->render($context->getLanguageInfo()->localeCode, ModelForm::trader($this->shop->get($channel, $context->getLanguageId())));
    }
}
