<?php declare(strict_types=1);

namespace Shopproof\Compliance\Cancel;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopproof\Compliance\Withdrawal\LookupLimit;
use Shopproof\Compliance\Withdrawal\OrderLink;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Keeps a cancellation and sends the confirmation to the customer and the copy to the shop,
 * through the shop's own Shopware mail setup. Five per connection and a hundred per shop in an
 * hour; the IP address only as a keyed hash in the shop's cache for that hour.
 */
final class CancelService
{
    public function __construct(
        private readonly CancelForm $form,
        private readonly CancelStore $store,
        private readonly SystemConfigService $config,
        private readonly AbstractMailService $mail,
        private readonly CacheItemPoolInterface $cache,
        private readonly OrderLink $link,
        private readonly LoggerInterface $logger,
        private readonly Connection $connection,
    ) {
    }

    public function on(?string $salesChannelId): bool
    {
        return $this->config->getBool(CancelForm::SETTING, $salesChannelId);
    }

    /**
     * The shop's email for the closed page and the copy, '' when none is set.
     */
    public function shopEmail(?string $salesChannelId): string
    {
        $mail = $this->config->get('core.basicInformation.email', $salesChannelId);

        return \is_string($mail) && filter_var($mail, \FILTER_VALIDATE_EMAIL) !== false ? $mail : '';
    }

    /**
     * Keeps the cancellation and sends both emails. The same one sent again within ten minutes
     * gives back the first record. Null when a limit is reached or it could not be kept.
     *
     * @param array{name: string, email: string, contract: string, cancelType: string, reason: string, endMode: string, endDate: string} $data
     *
     * @return array<string, string>|null
     */
    public function submit(array $data, string $lang, string $salesChannelId, string $ip): ?array
    {
        $print = CancelForm::fingerprint($data, $salesChannelId);
        try {
            $first = $this->store->recent($print);
            if ($first !== null) {
                return $this->store->find($first);
            }
            $hash = substr(hash_hmac('sha256', $ip, $this->link->token('cancel', 'limit')), 0, 32);
            $now = time();
            if (!LookupLimit::allow($this->cache, 'shopproof_cancel_' . $hash, $now, CancelForm::RATE_LIMIT)
                || !LookupLimit::allow($this->cache, 'shopproof_cancel_shop', $now, CancelForm::SITE_LIMIT)) {
                return null;
            }
            $id = $this->store->add($data, $salesChannelId, CancelForm::reference(), $lang, $print, new \DateTimeImmutable());
            $record = $this->store->find($id);
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not keep a cancellation: ' . $e->getMessage());

            return null;
        }
        if ($record === null) {
            return null;
        }
        [$date, $time] = self::when($record['receivedAt'], $lang);
        $shopName = $this->config->get('core.basicInformation.shopName', $salesChannelId);
        $receipt = $this->form->receipt($record, $date, $time, \is_string($shopName) ? $shopName : '');
        $receiptSent = $this->send($record['email'], $record['name'], $receipt, $salesChannelId, $this->shopEmail($salesChannelId));
        $shopMail = $this->shopEmail($salesChannelId);
        $shopSent = false;
        if ($shopMail !== '') {
            $shopLang = $this->form->lang($this->shopLocale($salesChannelId));
            [$shopDate, $shopTime] = self::when($record['receivedAt'], $shopLang);
            $shopSent = $this->send($shopMail, '', $this->form->shopCopy($record, $shopLang, $shopDate, $shopTime, $receiptSent), $salesChannelId, $record['email']);
        }
        $this->store->setSent($record['id'], $receiptSent, $shopSent);
        $record['receiptSent'] = $receiptSent ? '1' : '0';
        $record['shopCopySent'] = $shopSent ? '1' : '0';

        return $record;
    }

    /**
     * Date and time of receipt in the server's time zone, named, in the page language.
     *
     * @return array{0: string, 1: string}
     */
    public static function when(string $iso, string $lang): array
    {
        try {
            $time = (new \DateTimeImmutable($iso !== '' ? $iso : 'now'))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            $time = new \DateTimeImmutable();
        }
        if (class_exists(\IntlDateFormatter::class)) {
            $date = \IntlDateFormatter::create($lang, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $time->getTimezone());
            $formatted = $date !== null ? $date->format($time) : false;
            if (\is_string($formatted)) {
                return [$formatted, $time->format('H:i T')];
            }
        }

        return [$time->format('Y-m-d'), $time->format('H:i T')];
    }

    /**
     * The locale of the sales channel's default language, for the shop's copy.
     */
    private function shopLocale(string $salesChannelId): string
    {
        try {
            $locale = $this->connection->fetchOne(
                'SELECT l.code FROM sales_channel sc JOIN language lang ON lang.id = sc.language_id JOIN locale l ON l.id = lang.locale_id WHERE sc.id = :id',
                ['id' => Uuid::fromHexToBytes($salesChannelId)]
            );
        } catch (\Throwable) {
            $locale = false;
        }

        return \is_string($locale) ? $locale : 'en';
    }

    /**
     * One plain text email. The texts go in as data, never as the template, because Shopware
     * renders mail contents as Twig.
     *
     * @param array{subject: string, body: string} $text
     */
    private function send(string $to, string $name, array $text, string $salesChannelId, string $replyTo): bool
    {
        if (filter_var($to, \FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $data = [
            'recipients' => [$to => $name !== '' ? $name : $to],
            'salesChannelId' => $salesChannelId,
            'senderName' => '{{ shopproofSender }}',
            'subject' => '{{ shopproofSubject }}',
            'contentPlain' => '{{ shopproofBody }}',
            'contentHtml' => '<p>{{ shopproofBody|nl2br }}</p>',
        ];
        if (filter_var($replyTo, \FILTER_VALIDATE_EMAIL) !== false) {
            $data['replyTo'] = $replyTo;
        }
        $sender = $this->config->get('core.basicInformation.shopName', $salesChannelId);
        try {
            $sent = $this->mail->send(
                $data,
                new Context(new SystemSource(), [], Defaults::CURRENCY, [Defaults::LANGUAGE_SYSTEM]),
                [
                    'shopproofSender' => \is_string($sender) && $sender !== '' ? $sender : 'Shop',
                    'shopproofSubject' => $text['subject'],
                    'shopproofBody' => $text['body'],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not send a cancellation email: ' . $e->getMessage());

            return false;
        }

        return $sent !== null;
    }
}
