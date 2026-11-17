<?php declare(strict_types=1);

namespace Shopproof\Compliance\Twig;

use Shopproof\Compliance\Cancel\CancelForm;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The cancel link's words for the footer, in the page language, or null while the setting is off.
 */
final class CancelExtension extends AbstractExtension
{
    public function __construct(
        private readonly SystemConfigService $config,
        private readonly CancelForm $form,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shopproof_cancel_link', $this->link(...)),
        ];
    }

    /**
     * @return array{label: string, lang: string}|null
     */
    public function link(?SalesChannelContext $context = null): ?array
    {
        if ($context === null || !$this->config->getBool(CancelForm::SETTING, $context->getSalesChannelId())) {
            return null;
        }
        $lang = $this->form->lang($context->getLanguageInfo()->localeCode);

        return ['label' => $this->form->text('link', $lang), 'lang' => $lang];
    }
}
