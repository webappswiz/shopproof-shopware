<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the withdrawal line (OrderEmailLine) to the end of the order confirmation and "shipped"
 * emails, linking to the page of Shopware's own withdrawal button. Only when the setting is on,
 * the button is on and its page is set for the sales channel, and the email's language has a
 * reviewed wording. Never stops an email: a failure here is logged and the email goes out as it is.
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
            if (!$this->config->getBool(self::CONFIG . 'orderEmailWithdrawalLine', $channel)) {
                return;
            }
            if (!\in_array($this->templateType($template), self::TEMPLATE_TYPES, true)) {
                return;
            }
            $url = $this->withdrawalUrl($channel, $language);
            $words = $this->line->words($this->locale($language), $this->days($channel));
            if ($url === '' || $words === null) {
                return;
            }
            $content = OrderEmailLine::add($data['contentHtml'], \is_string($data['contentPlain'] ?? null) ? $data['contentPlain'] : '', $words, $url);
            $event->addData('contentHtml', $content['html']);
            $event->addData('contentPlain', $content['plain']);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not add the withdrawal line to an order email: ' . $e->getMessage());
        }
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
        $domain = $this->connection->fetchOne(
            'SELECT url FROM sales_channel_domain WHERE sales_channel_id = :channel ORDER BY language_id = :language DESC, url LIKE \'https://%\' DESC, url LIMIT 1',
            ['channel' => Uuid::fromHexToBytes($channel), 'language' => Uuid::fromHexToBytes($language)]
        );
        if (!\is_string($domain) || $domain === '') {
            return '';
        }
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
        $path = \is_string($path) && $path !== '' ? '/' . ltrim($path, '/') : '/page/cms/' . $page;

        return rtrim($domain, '/') . $path;
    }

    private function templateType(string $template): string
    {
        $type = $this->connection->fetchOne(
            'SELECT t.technical_name FROM mail_template m INNER JOIN mail_template_type t ON t.id = m.mail_template_type_id WHERE m.id = :id',
            ['id' => Uuid::fromHexToBytes($template)]
        );

        return \is_string($type) ? $type : '';
    }

    private function locale(string $language): string
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
