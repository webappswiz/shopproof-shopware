<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the items the customer ticked, the customer's status page link, and the shop's returns
 * portal when it has one, to the confirmation Shopware's withdrawal flow sends the customer, as
 * the WooCommerce plugin and the Shopify app do in their receipts. The shop's copy gets the items
 * and names those the shop excludes from withdrawal. The record is kept
 * just before that email goes out, so it is found by the customer's email in the same storefront.
 */
final class ReceiptEmailSubscriber implements EventSubscriberInterface
{
    public const TEMPLATE_TYPE = 'revocation_request.customer';
    public const MERCHANT_TYPE = 'revocation_request.merchant';

    public function __construct(
        private readonly WithdrawalStore $store,
        private readonly WithdrawalStatus $status,
        private readonly OrderEmailSubscriber $email,
        private readonly OrderEmailLine $line,
        private readonly LoggerInterface $logger,
        private readonly ReturnsWebhook $returns,
        private readonly WithdrawalItems $items,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [MailBeforeValidateEvent::class => ['onMail', -10]];
    }

    public function onMail(MailBeforeValidateEvent $event): void
    {
        try {
            $data = $event->getData();
            $channel = \is_string($data['salesChannelId'] ?? null) && Uuid::isValid($data['salesChannelId']) ? strtolower($data['salesChannelId']) : '';
            $template = \is_string($data['templateId'] ?? null) && Uuid::isValid($data['templateId']) ? $data['templateId'] : '';
            $recipients = $data['recipients'] ?? null;
            if ($channel === '' || $template === '' || !\is_array($recipients) || \count($recipients) !== 1 || !\is_string($data['contentHtml'] ?? null)) {
                return;
            }
            $type = $this->email->templateType($template);
            if ($type === self::MERCHANT_TYPE) {
                $this->merchantCopy($event, $channel);

                return;
            }
            if ($type !== self::TEMPLATE_TYPE) {
                return;
            }
            $id = $this->store->latestFor($channel, (string) array_key_first($recipients));
            $record = $id !== null ? $this->store->find($id) : null;
            if ($record === null) {
                return;
            }
            $language = $record['languageId'] !== '' ? $record['languageId'] : strtolower($event->getContext()->getLanguageId());
            $locale = $this->email->locale($language);
            $html = $data['contentHtml'];
            $plain = \is_string($data['contentPlain'] ?? null) ? $data['contentPlain'] : '';
            // The items the customer ticked, since Shopware's own email does not list them.
            $label = $this->line->itemsLabel($locale);
            if ($label !== null && $record['items'] !== '') {
                ['html' => $html, 'plain' => $plain] = OrderEmailLine::addText($html, $plain, $label . ":\n" . $record['items'], OrderEmailLine::ITEMS_MARKER);
            }
            $words = $this->line->statusWords($locale);
            $url = $this->status->url($record['id'], $channel, $language);
            if ($words !== null && $url !== '') {
                ['html' => $html, 'plain' => $plain] = OrderEmailLine::addStatus($html, $plain, $words, $url);
            }
            // The shop's returns portal, when it has one: where the customer starts sending back.
            $words = $this->line->returnsWords($locale);
            $portal = $this->returns->portalUrl($channel);
            if ($words !== null && $portal !== '') {
                ['html' => $html, 'plain' => $plain] = OrderEmailLine::addReturns($html, $plain, $words, $portal);
            }
            if ($html !== $data['contentHtml']) {
                $event->addData('contentHtml', $html);
                $event->addData('contentPlain', $plain);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not add the status link to the withdrawal receipt: ' . $e->getMessage());
        }
    }

    /**
     * The shop's copy: the ticked items, and the order's items the shop excludes from withdrawal,
     * so the shop checks each case. In the shop's language (the mail's context).
     */
    private function merchantCopy(MailBeforeValidateEvent $event, string $channel): void
    {
        $data = $event->getData();
        $form = $event->getTemplateData()[WithdrawalRecord::FORM_DATA] ?? null;
        $customer = \is_array($form) && \is_string($form['email'] ?? null) ? $form['email'] : '';
        $id = $customer !== '' ? $this->store->latestFor($channel, $customer) : null;
        $record = $id !== null ? $this->store->find($id) : null;
        if ($record === null || !\is_string($data['contentHtml'] ?? null)) {
            return;
        }
        $locale = $this->email->locale(strtolower($event->getContext()->getLanguageId()));
        $html = $data['contentHtml'];
        $plain = \is_string($data['contentPlain'] ?? null) ? $data['contentPlain'] : '';
        $label = $this->line->itemsLabel($locale) ?? $this->line->itemsLabel('en');
        if ($label !== null && $record['items'] !== '') {
            ['html' => $html, 'plain' => $plain] = OrderEmailLine::addText($html, $plain, $label . ":\n" . $record['items'], OrderEmailLine::ITEMS_MARKER);
        }
        $names = $record['orderId'] !== '' ? $this->items->excludedNames($record['orderId'], $channel, $event->getContext()) : [];
        $line = $this->line->excludedLine($locale);
        if ($names !== [] && $line !== null) {
            ['html' => $html, 'plain' => $plain] = OrderEmailLine::addText($html, $plain, str_replace('%s', implode(', ', $names), $line), OrderEmailLine::EXCLUDED_MARKER);
        }
        if ($html !== $data['contentHtml']) {
            $event->addData('contentHtml', $html);
            $event->addData('contentPlain', $plain);
        }
    }
}
