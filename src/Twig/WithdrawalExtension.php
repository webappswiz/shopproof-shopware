<?php declare(strict_types=1);

namespace Shopproof\Compliance\Twig;

use Shopproof\Compliance\Withdrawal\OrderEmailSubscriber;
use Shopproof\Compliance\Withdrawal\OrderLink;
use Shopproof\Compliance\Withdrawal\WithdrawalPrefill;
use Shopproof\Compliance\Withdrawal\Wording;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Storefront helpers around Shopware's own withdrawal button: the form filled in from a signed
 * order link, the order date field, the chosen button wording, and the link in the customer
 * account. Each helper returns nothing (and Shopware's own output stays) when its setting is off.
 */
final class WithdrawalExtension extends AbstractExtension
{
    private const CONFIG = 'ShopproofCompliance.config.';

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly RequestStack $requests,
        private readonly WithdrawalPrefill $prefill,
        private readonly Wording $wording,
        private readonly OrderLink $link,
        private readonly OrderEmailSubscriber $email,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('shopproof_withdrawal_prefill', $this->prefillData(...)),
            new TwigFunction('shopproof_withdrawal_labels', $this->labels(...)),
            new TwigFunction('shopproof_withdrawal_order_date', $this->orderDateMode(...)),
            new TwigFunction('shopproof_withdrawal_order_url', $this->orderUrl(...)),
            new TwigFunction('shopproof_withdrawal_picker', $this->pickerOn(...)),
            new TwigFunction('shopproof_withdrawal_comment', $this->commentMode(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('shopproof_withdraw_label', Wording::swapLink(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * The form values to show: what the shopper typed after a failed send, else the order from a
     * signed link, else nothing.
     */
    public function prefillData(mixed $data, ?SalesChannelContext $context): mixed
    {
        if ($data instanceof DataBag && $data->count() > 0) {
            return $data;
        }
        $request = $this->requests->getMainRequest();
        if ($request === null) {
            return $data;
        }
        $fields = $this->prefill->fromQuery($request->query->all(), $context?->getSalesChannelId());
        if ($fields === null) {
            return $data;
        }
        unset($fields['orderId']);
        if ($this->orderDateMode($context) === 'off') {
            unset($fields['orderDate']);
        }

        return new DataBag($fields);
    }

    /**
     * The chosen wording for the page language, or null while the national snippets apply.
     *
     * @return array{withdraw: string, confirm: string, source: string}|null
     */
    public function labels(?SalesChannelContext $context): ?array
    {
        if ($context === null) {
            return null;
        }

        return $this->wording->labels($context->getSalesChannelId(), self::lang($context));
    }

    /**
     * Whether the form asks for the order date: off (default), optional or required.
     */
    public function orderDateMode(?SalesChannelContext $context): string
    {
        $mode = $this->config->getString(self::CONFIG . 'withdrawalOrderDate', $context?->getSalesChannelId());

        return \in_array($mode, ['optional', 'required'], true) ? $mode : 'off';
    }

    /**
     * Whether the form lists the order's items to tick (on by default).
     */
    public function pickerOn(?SalesChannelContext $context): bool
    {
        return $this->config->get(self::CONFIG . 'itemPicker', $context?->getSalesChannelId()) !== false;
    }

    /**
     * Shopware's comment field: optional (default), required or off.
     */
    public function commentMode(?SalesChannelContext $context): string
    {
        $mode = $this->config->getString(self::CONFIG . 'withdrawalComment', $context?->getSalesChannelId());

        return \in_array($mode, ['required', 'off'], true) ? $mode : 'optional';
    }

    /**
     * The signed withdrawal link for an order in the customer account, '' when the setting is off,
     * the button is off or has no page.
     */
    public function orderUrl(mixed $order, ?SalesChannelContext $context): string
    {
        if ($context === null || !$this->config->getBool(self::CONFIG . 'accountWithdrawalLink', $context->getSalesChannelId())) {
            return '';
        }
        [$orderId, $email] = OrderEmailSubscriber::orderKeys($order);
        if ($orderId === '' || $email === '') {
            return '';
        }
        $page = $this->email->withdrawalUrl($context->getSalesChannelId(), $context->getLanguageId());

        return $page === '' ? '' : $this->link->url($page, $orderId, $email);
    }

    private static function lang(SalesChannelContext $context): string
    {
        return strtolower(substr($context->getLanguageInfo()->localeCode, 0, 2));
    }
}
