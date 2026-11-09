<?php declare(strict_types=1);

namespace Shopproof\Compliance\Withdrawal;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Sends each withdrawal on to the shop's returns tool, as the WooCommerce plugin and the Shopify
 * app do: the same signed JSON body (event "withdrawal.received", version 1), posted from the
 * message queue so the customer's form never waits for it. A tool that does not answer with 2xx
 * gets three more tries (after 5 minutes, 1 hour and 6 hours); after the last one the shop gets
 * an email without customer details. Only https addresses on the public internet are called, and
 * redirects are not followed, so the address cannot be pointed at the shop's own network.
 */
class ReturnsWebhook
{
    public const EVENT = 'withdrawal.received';
    public const TEST_EVENT = 'withdrawal.test';
    public const HEADER_EVENT = 'X-Shopproof-Event';
    public const HEADER_ID = 'X-Shopproof-Delivery';
    public const HEADER_SIG = 'X-Shopproof-Signature';
    public const RETRY_DELAYS = [300, 3600, 21600];
    public const SECRET = 'ShopproofCompliance.returnsWebhookSecret';
    public const STATES = ['queued', 'sent', 'retrying', 'failed'];
    /** For the integration test only: a server setting that also allows http and local addresses. */
    public const ALLOW_PRIVATE_ENV = 'SHOPPROOF_WEBHOOK_ALLOW_PRIVATE';

    private const CONFIG = 'ShopproofCompliance.config.';
    private const TIMEOUT = 10;
    private const MAX_URL = 500;

    public function __construct(
        private readonly SystemConfigService $config,
        private readonly WithdrawalStore $store,
        private readonly MessageBusInterface $bus,
        private readonly ClientInterface $client,
        private readonly AbstractMailService $mail,
        private readonly OrderEmailSubscriber $email,
        private readonly LoggerInterface $logger,
        private readonly string $version = '',
    ) {
    }

    /**
     * A usable https address, or '' (empty, not https, user and password in it, no dot in the
     * host, or a literal address outside the public internet). Names are checked again when sent.
     */
    public static function checkUrl(mixed $value, bool $allowPrivate = false): string
    {
        $url = \is_string($value) ? trim($value) : '';
        if ($url === '' || \strlen($url) > self::MAX_URL || preg_match('/\s/', $url)) {
            return '';
        }
        $parts = parse_url($url);
        if (!\is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($allowPrivate) {
            return \in_array($scheme, ['https', 'http'], true) && $host !== '' ? $url : '';
        }
        if ($scheme !== 'https' || $host === '' || !str_contains($host, '.') && !str_contains($host, ':')) {
            return '';
        }
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false && !self::publicIp($host)) {
            return '';
        }
        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return '';
        }

        return $url;
    }

    /**
     * An address on the public internet. Also refuses the IPv6 forms that carry an IPv4 address
     * inside (NAT64 64:ff9b::/96 and 6to4 2002::/16), which could lead to a local one.
     */
    public static function publicIp(string $ip): bool
    {
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE) !== $ip) {
            return false;
        }
        $bytes = inet_pton($ip);
        if (\is_string($bytes) && \strlen($bytes) === 16) {
            if (str_starts_with($bytes, "\x00\x64\xff\x9b") || str_starts_with($bytes, "\x20\x02")) {
                return false;
            }
        }

        return true;
    }

    /**
     * The JSON body, the same fields as WooCommerce and Shopify.
     *
     * @param array{reference: string, receivedAt: string, name: string, email: string, order: string, orderId: string, orderDate: string, items: string, country: string, language: string, details: string, shopName: string, shopUrl: string} $data
     *
     * @return array<string, mixed>
     */
    public static function payload(array $data, string $event = self::EVENT, string $version = ''): array
    {
        $payload = [
            'event' => $event,
            'version' => 1,
            'reference' => $data['reference'],
            'received_at' => $data['receivedAt'],
            'customer' => ['name' => $data['name'], 'email' => $data['email']],
            'order' => [
                'number' => $data['order'],
                'id' => $data['orderId'],
                'date' => $data['orderDate'],
                'items' => $data['items'],
                'whole_order' => $data['items'] === '',
            ],
            'country' => $data['country'],
            'language' => $data['language'],
            'shop' => ['name' => $data['shopName'], 'url' => $data['shopUrl']],
            'platform' => 'shopware',
            'plugin_version' => $version,
        ];
        if ($data['details'] !== '') {
            $payload['details'] = $data['details'];
        }

        return $payload;
    }

    /**
     * The signature header: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">.
     */
    public static function signature(string $body, string $secret, int $time): string
    {
        return 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret);
    }

    /**
     * The state after a try: sent, retrying (with the delay before the next try) or failed.
     *
     * @return array{status: string, delay: int}
     */
    public static function nextState(int $attempt, bool $ok): array
    {
        if ($ok) {
            return ['status' => 'sent', 'delay' => 0];
        }
        if ($attempt <= \count(self::RETRY_DELAYS)) {
            return ['status' => 'retrying', 'delay' => self::RETRY_DELAYS[$attempt - 1]];
        }

        return ['status' => 'failed', 'delay' => 0];
    }

    /**
     * The returns tool's address for a sales channel, '' when none or not usable.
     */
    public function url(?string $channel): string
    {
        return self::checkUrl($this->config->get(self::CONFIG . 'returnsWebhookUrl', $channel), $this->allowPrivate());
    }

    /**
     * Whether an address is filled in but cannot be used (for the setup check).
     */
    public function urlRejected(?string $channel): bool
    {
        $raw = $this->config->get(self::CONFIG . 'returnsWebhookUrl', $channel);

        return \is_string($raw) && trim($raw) !== '' && $this->url($channel) === '';
    }

    /**
     * The shop's returns portal for customers, '' when none or not https.
     */
    public function portalUrl(?string $channel): string
    {
        $raw = $this->config->get(self::CONFIG . 'returnsPortalUrl', $channel);
        $url = \is_string($raw) ? trim($raw) : '';

        return $url !== '' && \strlen($url) <= self::MAX_URL && str_starts_with(strtolower($url), 'https://') && filter_var($url, \FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    /**
     * The signing secret ("whsec_" and 48 hex characters), made on first use or when renewed.
     */
    public function secret(bool $renew = false): string
    {
        $secret = $this->config->get(self::SECRET);
        if ($renew || !\is_string($secret) || !preg_match('/^whsec_[0-9a-f]{48}$/', $secret)) {
            $secret = 'whsec_' . bin2hex(random_bytes(24));
            $this->config->set(self::SECRET, $secret);
        }

        return $secret;
    }

    /**
     * After a withdrawal is recorded: queue its first try when the channel has a returns tool.
     */
    public function queue(string $id, ?string $channel): void
    {
        if ($this->url($channel) === '') {
            return;
        }
        $this->store->setWebhook($id, 'queued', 0, null);
        $this->bus->dispatch(new ReturnsWebhookMessage($id, 1));
    }

    /**
     * One try, from the message queue.
     */
    public function deliver(string $id, int $attempt): void
    {
        $facts = $this->store->webhookFacts($id);
        if ($facts === null) {
            return;
        }
        $channel = $facts['salesChannelId'] !== '' ? $facts['salesChannelId'] : null;
        $url = $this->url($channel);
        if ($url === '') {
            return;
        }
        $attempt = max(1, $attempt);
        $result = $this->send($url, self::payload($this->data($facts), self::EVENT, $this->version));
        $state = self::nextState($attempt, $result['ok']);
        $this->store->setWebhook($id, $state['status'], $attempt, $result['code']);
        if ($state['status'] === 'retrying') {
            $this->bus->dispatch(new Envelope(new ReturnsWebhookMessage($id, $attempt + 1), [new DelayStamp($state['delay'] * 1000)]));
        } elseif ($state['status'] === 'failed') {
            $this->notifyFailure($facts, $result['code']);
        }
    }

    /**
     * "Send a test" on the Shopproof page: a made-up withdrawal with the test event.
     *
     * @return array{ok: bool, code: int, error: string}
     */
    public function sendTest(?string $channel): array
    {
        $url = $this->url($channel);
        if ($url === '') {
            return ['ok' => false, 'code' => 0, 'error' => 'no_url'];
        }
        $data = [
            'reference' => '00000000000000000000000000000000',
            'receivedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'order' => '10001',
            'orderId' => '',
            'orderDate' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'),
            'items' => 'Test item',
            'country' => '',
            'language' => 'en',
            'details' => '',
        ] + $this->shop($channel, null);

        return $this->send($url, self::payload($data, self::TEST_EVENT, $this->version));
    }

    /**
     * Posts one body. Never throws.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{ok: bool, code: int, error: string}
     */
    public function send(string $url, array $payload): array
    {
        try {
            $options = $this->pin($url);
            if ($options === null) {
                return ['ok' => false, 'code' => 0, 'error' => 'blocked'];
            }
            $body = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            $response = $this->client->request('POST', $url, $options + [
                'timeout' => self::TIMEOUT,
                'connect_timeout' => 5,
                'allow_redirects' => false,
                'http_errors' => false,
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'User-Agent' => 'ShopproofWebhook/1.0 (+https://shopproof.app/help/)',
                    self::HEADER_EVENT => \is_string($payload['event'] ?? null) ? $payload['event'] : self::EVENT,
                    self::HEADER_ID => \is_string($payload['reference'] ?? null) ? $payload['reference'] : '',
                    self::HEADER_SIG => self::signature($body, $this->secret(), time()),
                ],
                'body' => $body,
            ]);
            $code = $response->getStatusCode();

            return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'error' => ''];
        } catch (\Throwable $e) {
            $this->logger->warning('Shopproof could not reach the returns tool: ' . $e->getMessage());

            return ['ok' => false, 'code' => 0, 'error' => 'unreachable'];
        }
    }

    /**
     * The request options that pin the host to an address checked to be on the public internet,
     * so a name cannot resolve to the shop's own network between the check and the call. Null
     * when the host has no public address.
     *
     * @return array<string, mixed>|null
     */
    private function pin(string $url): ?array
    {
        if ($this->allowPrivate()) {
            return [];
        }
        $host = strtolower(trim((string) parse_url($url, \PHP_URL_HOST), '[]'));
        $port = parse_url($url, \PHP_URL_PORT) ?? 443;
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return self::publicIp($host) ? [] : null;
        }
        $ips = gethostbynamel($host) ?: [];
        $six = @dns_get_record($host, \DNS_AAAA) ?: [];
        foreach ($six as $record) {
            if (\is_string($record['ipv6'] ?? null)) {
                $ips[] = $record['ipv6'];
            }
        }
        if ($ips === [] || array_filter($ips, static fn (string $ip): bool => !self::publicIp($ip)) !== []) {
            return null;
        }
        $ip = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];

        return ['curl' => [\CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip]]];
    }

    private function allowPrivate(): bool
    {
        $value = $_SERVER[self::ALLOW_PRIVATE_ENV] ?? $_ENV[self::ALLOW_PRIVATE_ENV] ?? getenv(self::ALLOW_PRIVATE_ENV);

        return $value === '1' || $value === 'true';
    }

    /**
     * @param array{id: string, salesChannelId: string, languageId: string, locale: string, firstName: string, lastName: string, email: string, contractNumber: string, orderNumber: string, orderId: string, orderDate: string, comment: string, items: string, submittedAt: string, country: string} $facts
     *
     * @return array{reference: string, receivedAt: string, name: string, email: string, order: string, orderId: string, orderDate: string, items: string, country: string, language: string, details: string, shopName: string, shopUrl: string}
     */
    private function data(array $facts): array
    {
        return [
            'reference' => $facts['id'],
            'receivedAt' => $facts['submittedAt'],
            'name' => trim($facts['firstName'] . ' ' . $facts['lastName']),
            'email' => $facts['email'],
            'order' => $facts['orderNumber'] !== '' ? $facts['orderNumber'] : $facts['contractNumber'],
            'orderId' => $facts['orderId'],
            'orderDate' => $facts['orderDate'],
            'items' => $facts['items'],
            'country' => $facts['country'],
            'language' => strtolower(substr($facts['locale'], 0, 2)),
            'details' => $facts['comment'],
        ] + $this->shop($facts['salesChannelId'] !== '' ? $facts['salesChannelId'] : null, $facts['languageId'] !== '' ? $facts['languageId'] : null);
    }

    /**
     * @return array{shopName: string, shopUrl: string}
     */
    private function shop(?string $channel, ?string $language): array
    {
        $name = $this->config->get('core.basicInformation.shopName', $channel);
        $url = $channel !== null && Uuid::isValid($channel) ? $this->email->domain($channel, $language !== null && Uuid::isValid($language) ? $language : Defaults::LANGUAGE_SYSTEM) : '';

        return ['shopName' => \is_string($name) ? $name : '', 'shopUrl' => $url !== '' ? $url . '/' : ''];
    }

    /**
     * The email to the shop after the last failed try. No customer details: the reference finds
     * the withdrawal on the Shopproof page.
     *
     * @param array{id: string, salesChannelId: string, languageId: string, locale: string} $facts
     */
    private function notifyFailure(array $facts, int $code): void
    {
        $channel = $facts['salesChannelId'] !== '' ? $facts['salesChannelId'] : null;
        $to = $this->config->get('core.basicInformation.email', $channel);
        if ($channel === null || !\is_string($to) || filter_var($to, \FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        $text = self::failureText($facts['id'], $code);
        try {
            $this->mail->send(
                [
                    'recipients' => [$to => $to],
                    'salesChannelId' => $channel,
                    'senderName' => 'Shopproof',
                    'subject' => $text['subject'],
                    'contentHtml' => nl2br(htmlspecialchars($text['body'], \ENT_QUOTES | \ENT_HTML5, 'UTF-8')),
                    'contentPlain' => $text['body'],
                ],
                new Context(new SystemSource(), [], Defaults::CURRENCY, [Defaults::LANGUAGE_SYSTEM]),
                []
            );
        } catch (\Throwable $e) {
            $this->logger->error('Shopproof could not email the shop about a failed returns tool delivery: ' . $e->getMessage());
        }
    }

    /**
     * The failure email's words, in English (the Shopproof page shows the same in the admin's
     * language). Braces are escaped since Shopware renders mail texts as templates.
     *
     * @return array{subject: string, body: string}
     */
    public static function failureText(string $reference, int $code): array
    {
        $reason = $code > 0 ? 'Your returns tool answered with status ' . $code . '.' : 'Your returns tool could not be reached.';

        return [
            'subject' => 'Withdrawal ' . $reference . ' did not reach your returns tool',
            'body' => 'Shopproof tried four times to send withdrawal ' . $reference . ' to your returns tool and gave up. ' . $reason
                . "\n\nThe withdrawal itself is saved and the customer has their receipt. Please create the return by hand, and check the returns tool address under Settings > Extensions > Shopproof.",
        ];
    }
}
