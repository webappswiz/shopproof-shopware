<?php declare(strict_types=1);

namespace Shopproof\Compliance\Checkout;

use Psr\Log\LoggerInterface;
use Shopproof\Compliance\Notes\CheckoutBoxesSubscriber;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * When the merchant asks about an early start for services (setting serviceConsent): checks the
 * two boxes on the storefront's own order button for carts with a service (both required, or in
 * optional mode both or neither), and saves both statements with the time on the order when the
 * shopper ticked both. Orders placed through the Store API by other front ends are not blocked,
 * because nobody shows them the boxes. Carts without a service are never touched.
 */
final class ServiceConsentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $config,
        private readonly RequestStack $requests,
        private readonly CartService $carts,
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
        $context = $request->attributes->get('sw-sales-channel-context');
        if (!$context instanceof SalesChannelContext) {
            return;
        }
        $mode = ServiceWithdrawal::mode($this->config->get(ServiceWithdrawal::SETTING, $context->getSalesChannelId()));
        if ($mode === '') {
            return;
        }
        try {
            // Shopware's order service has just loaded this cart; the cart service keeps it for the request.
            if (!ServiceWithdrawal::hasService($this->carts->getCart($context->getToken(), $context)->getLineItems()->getFlat())) {
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not read the cart for the service boxes: ' . $e->getMessage());

            return;
        }
        if ($mode === 'required') {
            $event->getDefinition()->add(ServiceWithdrawal::CONSENT_FIELD, new NotBlank());
            $event->getDefinition()->add(ServiceWithdrawal::ACK_FIELD, new NotBlank());

            return;
        }
        $data = $request->request;
        $consent = DigitalConsent::ticked($data->get(ServiceWithdrawal::CONSENT_FIELD));
        $ack = DigitalConsent::ticked($data->get(ServiceWithdrawal::ACK_FIELD));
        if (ServiceWithdrawal::pairError($mode, $consent, $ack) === '') {
            return;
        }
        // One box alone is not a request: the empty one is marked, with the reason.
        $empty = $consent ? ServiceWithdrawal::ACK_FIELD : ServiceWithdrawal::CONSENT_FIELD;
        $message = $this->translator->trans('shopproof.service.pair');
        $event->getDefinition()->add($empty, new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($message): void {
            $context->addViolation($message);
        }));
    }

    public function onConverted(CartConvertedEvent $event): void
    {
        try {
            $context = $event->getSalesChannelContext();
            $mode = ServiceWithdrawal::mode($this->config->get(ServiceWithdrawal::SETTING, $context->getSalesChannelId()));
            if ($mode === '' || !ServiceWithdrawal::hasService($event->getCart()->getLineItems()->getFlat())) {
                return;
            }
            $request = $this->requests->getMainRequest();
            if ($request === null) {
                return;
            }
            $data = $request->request;
            if (!DigitalConsent::ticked($data->get(ServiceWithdrawal::CONSENT_FIELD)) || !DigitalConsent::ticked($data->get(ServiceWithdrawal::ACK_FIELD))) {
                return;
            }
            $order = $event->getConvertedCart();
            $fields = \is_array($order['customFields'] ?? null) ? $order['customFields'] : [];
            $fields[ServiceWithdrawal::FIELD] = ServiceWithdrawal::record(
                new \DateTimeImmutable(),
                $this->translator->trans('shopproof.service.consent'),
                $this->translator->trans('shopproof.service.ack')
            );
            $order['customFields'] = $fields;
            $event->setConvertedCart($order);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not save the service consent on an order: ' . $e->getMessage());
        }
    }
}
