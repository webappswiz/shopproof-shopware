<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopproof\Compliance\Checkout\DigitalConsent;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the withdrawal line (OrderEmailLine) to the end of the order confirmation and "shipped"
 * emails, linking to the page of Shopware's own withdrawal button. Only when the setting is on,
 * the button is on and its page is set for the sales channel, and the email's language has a
 * wording. The order confirmation also gets, above that line, the digital content consent the
 * shopper gave (DigitalConsent) and a line of links to the terms, withdrawal policy and privacy
 * pages set under Settings > Basic information. Never stops an email: a failure here is logged
 * and the email goes out as it is.
 */
final class OrderEmailSubscriber implements EventSubscriberInterface
{
    /** Shopware's mail template types where the line fits: the order is placed or on its way. */
    public const TEMPLATE_TYPES = ['order_confirmation_mail', 'order_delivery.state.shipped', 'order_delivery.state.shipped_partially'];

    private const CONFIG = 'ShopproofCompliance.config.';

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $config,
        private readonly OrderEmailLine $line,
        private readonly LoggerInterface $logger,
        private readonly OrderLink $orderLink,
        private readonly Wording $wording,
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
            $channel = self::hex($data['salesChannelId'] ?? null);
            // The flow often leaves languageId empty; the order's language is the email's language.
            $order = $event->getTemplateData()['order'] ?? null;
            $language = self::hex($data['languageId'] ?? null);
            if ($language === '' && \is_object($order) && method_exists($order, 'getLanguageId')) {
                $language = self::hex($order->getLanguageId());
            }
            if ($language === '') {
                $language = self::hex($event->getContext()->getLanguageId());
            }
            $template = self::hex($data['templateId'] ?? null);
            if ($channel === '' || $language === '' || $template === '' || !\is_string($data['contentHtml'] ?? null)) {
                return;
            }
            $type = $this->templateType($template);
            if (!\in_array($type, self::TEMPLATE_TYPES, true)) {
                return;
            }
            $locale = $this->locale($language);
            $content = ['html' => $data['contentHtml'], 'plain' => \is_string($data['contentPlain'] ?? null) ? $data['contentPlain'] : ''];
            $fields = \is_object($order) && method_exists($order, 'getCustomFields') ? $order->getCustomFields() : null;
            $consent = DigitalConsent::given(\is_array($fields) ? $fields : null);

            // In the order confirmation: the digital content consent the shopper gave (Art. 8(7)),
            // then the links to the legal pages, then (below) the withdrawal line at the very end.
            if ($type === 'order_confirmation_mail') {
                $confirm = $this->line->digitalConfirmation($locale);
                if ($consent && $confirm !== null) {
                    $content = OrderEmailLine::addText($content['html'], $content['plain'], $confirm, OrderEmailLine::DIGITAL_MARKER);
                }
                if ($this->config->getBool(self::CONFIG . 'orderEmailLegalLinks', $channel)) {
                    $links = $this->legalLinks($channel, $language, $locale);
                    if ($links !== []) {
                        $content = OrderEmailLine::addLegal($content['html'], $content['plain'], $links);
                    }
                }
            }

            $content = $this->withWithdrawalLine($content, $channel, $language, $locale, $order, $consent);
            if ($content['html'] !== $data['contentHtml']) {
                $event->addData('contentHtml', $content['html']);
                $event->addData('contentPlain', $content['plain']);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not add the withdrawal line to an order email: ' . $e->getMessage());
        }
    }

    /**
     * The withdrawal line (OrderEmailLine) at the very end, when the setting is on, the button
     * has a page and the email's language has a wording. After a digital content consent for an
     * order of downloads only, the line keeps the link but leaves out "You can withdraw within
     * 14 days", which would no longer be true once the download starts.
     *
     * @param array{html: string, plain: string} $content
     *
     * @return array{html: string, plain: string}
     */
    private function withWithdrawalLine(array $content, string $channel, string $language, string $locale, mixed $order, bool $consent): array
    {
        if (!$this->config->getBool(self::CONFIG . 'orderEmailWithdrawalLine', $channel)) {
            return $content;
        }
        $url = $this->withdrawalUrl($channel, $language);
        $words = $this->line->words($locale, $this->days($channel));
        if ($url === '' || $words === null) {
            return $content;
        }
        // Signed for this order, so the withdrawal form opens filled in (as the WooCommerce
        // plugin's one-tap link); without an order the plain page address is used.
        [$orderId, $email] = self::orderKeys($order);
        if ($orderId !== '' && $email !== '') {
            $url = $this->orderLink->url($url, $orderId, $email);
        }
        $label = $this->wording->labels($channel, $words['lang']);
        if ($label !== null) {
            $words['link'] = $label['withdraw'];
        }
        if ($consent && self::onlyDigital($order)) {
            $words['question'] = null;
            $words['period'] = null;
        }

        return OrderEmailLine::add($content['html'], $content['plain'], $words, $url);
    }

    /**
     * Whether every product in an order is a download.
     */
    public static function onlyDigital(mixed $order): bool
    {
        if (!\is_object($order) || !method_exists($order, 'getLineItems')) {
            return false;
        }
        $items = $order->getLineItems();
        if (!is_iterable($items)) {
            return false;
        }
        $products = 0;
        foreach ($items as $item) {
            if (!\is_object($item) || !method_exists($item, 'getType') || $item->getType() !== 'product' || !method_exists($item, 'getPayload')) {
                continue;
            }
            ++$products;
            $payload = $item->getPayload();
            if (\is_array($payload) && ($payload['productType'] ?? null) === 'digital') {
                continue;
            }
            // Shopware before 6.7.6 marks downloads only with a line item state, as at checkout.
            if (method_exists($item, 'getStates') && DigitalConsent::downloadState(static fn (): mixed => $item->getStates())) {
                continue;
            }

            return false;
        }

        return $products > 0;
    }

    /**
     * The terms, withdrawal policy and privacy pages set under Settings > Basic information, as
     * [label, address] pairs in the email's language; pages that are not set are left out.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function legalLinks(string $channel, string $language, string $locale): array
    {
        $labels = $this->line->legalLabels($locale);
        if ($labels === null) {
            return [];
        }
        $domain = $this->domain($channel, $language);
        if ($domain === '') {
            return [];
        }
        $links = [];
        foreach (['terms' => 'tosPage', 'withdrawal' => 'revocationPage', 'privacy' => 'privacyPage'] as $key => $setting) {
            $page = $this->config->get('core.basicInformation.' . $setting, $channel);
            if (\is_string($page) && Uuid::isValid($page)) {
                $links[] = [$labels[$key], $domain . $this->pagePath($page, $channel, $language)];
            }
        }

        return $links;
    }

    /**
     * The order's id and customer email from the mail's template data.
     *
     * @return array{0: string, 1: string}
     */
    public static function orderKeys(mixed $order): array
    {
        if (!\is_object($order) || !method_exists($order, 'getId') || !method_exists($order, 'getOrderCustomer')) {
            return ['', ''];
        }
        $id = $order->getId();
        $customer = $order->getOrderCustomer();
        $email = \is_object($customer) && method_exists($customer, 'getEmail') ? $customer->getEmail() : null;

        return [\is_string($id) ? strtolower($id) : '', \is_string($email) ? $email : ''];
    }

    /**
     * The shop's withdrawal period in days, 14 to 365.
     */
    public function days(?string $channel): int
    {
        return OrderEmailLine::period($this->config->getInt(self::CONFIG . 'withdrawalDays', $channel));
    }

    /**
     * The full address of the withdrawal button's page for a sales channel and language: its SEO
     * address when a category shows the page, else Shopware's own page address (the same one its
     * footer button uses). The channel's domain for the email's language, https before http. ''
     * when the button is off or has no page, or the channel has no domain.
     */
    public function withdrawalUrl(string $channel, string $language): string
    {
        $page = $this->config->get(SetupFacts::PAGE, $channel);
        if (!$this->config->getBool(SetupFacts::BUTTON, $channel) || !\is_string($page) || !Uuid::isValid($page)) {
            return '';
        }
        $domain = $this->domain($channel, $language);
        if ($domain === '') {
            return '';
        }
        return $domain . $this->pagePath($page, $channel, $language);
    }

    /**
     * The storefront path of a shop page: its SEO address when a category shows the page, else
     * Shopware's own page address (the one its footer button uses).
     */
    public function pagePath(string $page, string $channel, string $language): string
    {
        $path = $this->connection->fetchOne(
            'SELECT s.seo_path_info FROM seo_url s INNER JOIN category c ON c.id = s.foreign_key AND c.version_id = UNHEX(:live)
             WHERE c.cms_page_id = :page AND s.route_name = :route AND s.sales_channel_id = :channel AND s.language_id = :language
               AND s.is_canonical = 1 AND s.is_deleted = 0 LIMIT 1',
            [
                'live' => Defaults::LIVE_VERSION,
                'page' => Uuid::fromHexToBytes($page),
                'route' => 'frontend.navigation.page',
                'channel' => Uuid::fromHexToBytes($channel),
                'language' => Uuid::fromHexToBytes($language),
            ]
        );

        return \is_string($path) && $path !== '' ? '/' . ltrim($path, '/') : '/page/cms/' . strtolower($page);
    }

    /**
     * The storefront's address for a language (https before http), without a trailing slash, ''
     * when the sales channel has no domain.
     */
    public function domain(string $channel, string $language): string
    {
        $domain = $this->connection->fetchOne(
            'SELECT url FROM sales_channel_domain WHERE sales_channel_id = :channel ORDER BY language_id = :language DESC, url LIKE \'https://%\' DESC, url LIMIT 1',
            ['channel' => Uuid::fromHexToBytes($channel), 'language' => Uuid::fromHexToBytes($language)]
        );

        return \is_string($domain) ? rtrim($domain, '/') : '';
    }

    public function templateType(string $template): string
    {
        $type = $this->connection->fetchOne(
            'SELECT t.technical_name FROM mail_template m INNER JOIN mail_template_type t ON t.id = m.mail_template_type_id WHERE m.id = :id',
            ['id' => Uuid::fromHexToBytes($template)]
        );

        return \is_string($type) ? $type : '';
    }

    public function locale(string $language): string
    {
        $code = $this->connection->fetchOne(
            'SELECT l.code FROM language g INNER JOIN locale l ON l.id = g.locale_id WHERE g.id = :id',
            ['id' => Uuid::fromHexToBytes($language)]
        );

        return \is_string($code) ? $code : '';
    }

    private static function hex(mixed $value): string
    {
        return \is_string($value) && Uuid::isValid($value) ? strtolower($value) : '';
    }
}
