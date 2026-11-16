<?php declare(strict_types=1);

namespace Shopproof\Compliance\Checkout;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopproof\Compliance\Notes\CheckoutBoxesSubscriber;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * When the merchant turns on the digital content consent: requires the second box where Shopware
 * requires its own box (a cart with a download) on the storefront's own order button, where the
 * box is shown, and saves both statements with the time on the order. Orders placed through the
 * Store API by other front ends are not blocked, because nobody shows them the second box. Orders
 * without a download are never touched.
 */
final class DigitalConsentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $config,
        private readonly RequestStack $requests,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'framework.validation.order.create' => 'onValidation',
            CartConvertedEvent::class => 'onConverted',
        ];
    }

    public function onValidation(BuildValidationEvent $event): void
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null || $request->attributes->get('_route') !== CheckoutBoxesSubscriber::ORDER_ROUTE) {
            return;
        }
        $source = $event->getContext()->getSource();
        $channel = $source instanceof SalesChannelApiSource ? $source->getSalesChannelId() : null;
        // Shopware adds its own rule for the box only when the cart holds a download.
        if (!$this->config->getBool(DigitalConsent::SETTING, $channel) || $event->getDefinition()->getProperty(DigitalConsent::CONSENT_FIELD) === []) {
            return;
        }
        $event->getDefinition()->add(DigitalConsent::ACK_FIELD, new NotBlank());
    }

    public function onConverted(CartConvertedEvent $event): void
    {
        try {
            $context = $event->getSalesChannelContext();
            if (!$this->config->getBool(DigitalConsent::SETTING, $context->getSalesChannelId())
                || !DigitalConsent::hasDigital($event->getCart()->getLineItems()->getFlat())) {
                return;
            }
            $request = $this->requests->getMainRequest();
            if ($request === null) {
                return;
            }
            $data = $request->request;
            if (!DigitalConsent::ticked($data->get(DigitalConsent::CONSENT_FIELD)) || !DigitalConsent::ticked($data->get(DigitalConsent::ACK_FIELD))) {
                return;
            }
            $order = $event->getConvertedCart();
            $fields = \is_array($order['customFields'] ?? null) ? $order['customFields'] : [];
            $fields[DigitalConsent::FIELD] = DigitalConsent::record(
                new \DateTimeImmutable(),
                $this->translator->trans('shopproof.digital.consent'),
                $this->translator->trans('shopproof.digital.ack')
            );
            $order['customFields'] = $fields;
            $event->setConvertedCart($order);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not save the digital content consent on an order: ' . $e->getMessage());
        }
    }
}
