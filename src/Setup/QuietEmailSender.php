<?php declare(strict_types=1);

namespace Shopproof\Compliance\Setup;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The daily quiet email check (see QuietEmail). Reads the same cards as the top of the Shopproof
 * page and sends through Shopware's own mail service to the shop's email address
 * (Settings, Basic information). Contacts no one else.
 */
class QuietEmailSender
{
    public function __construct(
        private readonly OverviewData $overview,
        private readonly SystemConfigService $config,
        private readonly AbstractMailService $mail,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{off: bool, time: int, digest: string}
     */
    public function state(): array
    {
        $time = $this->config->get(QuietEmail::SENT_TIME);
        $digest = $this->config->get(QuietEmail::SENT_DIGEST);

        return [
            'off' => $this->config->getBool(QuietEmail::OFF),
            'time' => \is_int($time) ? $time : 0,
            'digest' => \is_string($digest) ? $digest : '',
        ];
    }

    public function on(): bool
    {
        return !$this->state()['off'];
    }

    public function switchTo(bool $on): void
    {
        $this->config->set(QuietEmail::OFF, !$on);
    }

    public function recipient(): string
    {
        $mail = $this->config->get('core.basicInformation.email');

        return \is_string($mail) && filter_var($mail, \FILTER_VALIDATE_EMAIL) !== false ? $mail : '';
    }

    /**
     * @return bool whether an email was handed to the mail service
     */
    public function run(?int $now = null): bool
    {
        $now ??= time();
        $state = $this->state();
        if ($state['off']) {
            return false;
        }
        $items = QuietEmail::urgent($this->overview->build(new \DateTimeZone(date_default_timezone_get()))['cards']);
        if ($items === []) {
            // Nothing open: the next problem is sent at once, even if it reads like an old one.
            if ($state['digest'] !== '') {
                $this->store(0, '');
            }

            return false;
        }
        $to = $this->recipient();
        if (!QuietEmail::due($items, $state, $now) || $to === '') {
            return false;
        }
        // Shopware's "Disable email delivery" setting: nothing would leave the shop, so nothing is
        // recorded as sent and the email goes out once delivery is back on.
        if ($this->config->getBool('core.mailerSettings.disableDelivery')) {
            return false;
        }
        $shop = $this->config->get('core.basicInformation.shopName');
        $shop = \is_string($shop) && trim($shop) !== '' ? trim($shop) : 'Shopware';
        $message = QuietEmail::message($items, self::snippets(QuietEmail::locale($this->systemLocale())), self::snippets('en-GB'), $shop, self::adminUrl());

        try {
            $sent = $this->mail->send(
                [
                    'recipients' => [$to => $to],
                    'senderName' => '{{ shopproofSender }}',
                    'subject' => '{{ shopproofSubject }}',
                    'contentPlain' => '{{ shopproofBody }}',
                    'contentHtml' => '<p>{{ shopproofBody|nl2br }}</p>',
                ],
                new Context(new SystemSource(), [], Defaults::CURRENCY, [Defaults::LANGUAGE_SYSTEM]),
                [
                    'shopproofSender' => $shop,
                    'shopproofSubject' => $message['subject'],
                    'shopproofBody' => $message['body'],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not send the quiet email: ' . $e->getMessage());

            return false;
        }
        if ($sent === null) {
            return false;
        }
        $this->store($now, QuietEmail::digest($items));

        return true;
    }

    private function store(int $time, string $digest): void
    {
        $this->config->set(QuietEmail::SENT_TIME, $time);
        $this->config->set(QuietEmail::SENT_DIGEST, $digest);
    }

    /**
     * The locale of the shop's system language.
     */
    private function systemLocale(): string
    {
        try {
            $code = $this->connection->fetchOne(
                'SELECT l.code FROM language lang JOIN locale l ON l.id = lang.locale_id WHERE lang.id = :id',
                ['id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]
            );
        } catch (\Throwable) {
            $code = false;
        }

        return \is_string($code) ? $code : 'en-GB';
    }

    /**
     * @return array<string, mixed>
     */
    public static function snippets(string $locale): array
    {
        $file = __DIR__ . '/../Resources/app/administration/src/snippet/' . $locale . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        $out = [];
        foreach (\is_array($data) ? $data : [] as $key => $value) {
            if (\is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function adminUrl(): string
    {
        $url = $_SERVER['APP_URL'] ?? getenv('APP_URL');

        return (\is_string($url) && $url !== '' ? rtrim($url, '/') : '') . '/admin#/shopproof/overview/index';
    }
}
