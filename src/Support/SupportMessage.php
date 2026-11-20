<?php declare(strict_types=1);

namespace Shopproof\Compliance\Support;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * The message form in the "Help and contact" card, the same as in the WooCommerce plugin and the
 * Shopify app. Nothing is sent until the merchant presses Send: then the shop's server posts the
 * name, email address and message they typed, the version numbers and (if they leave the tick on)
 * the shop address to shopproof.app, which emails it to support@shopproof.app and keeps no copy.
 */
final class SupportMessage
{
    public const EMAIL = 'support@shopproof.app';
    public const ENDPOINT = 'https://shopproof.app/api/contact';
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 4000;

    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * Check what the merchant typed.
     *
     * @param array<mixed> $input
     * @return array{ok: true, name: string, email: string, message: string, includeShop: bool}|array{ok: false, field: string, error: string}
     */
    public static function validate(array $input): array
    {
        $name = self::oneLine($input['name'] ?? '', 100);
        $email = self::oneLine($input['email'] ?? '', 254);
        $message = \is_string($input['message'] ?? null) ? trim(str_replace(["\r\n", "\r"], "\n", $input['message'])) : '';
        if ($email === '' || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'field' => 'email', 'error' => 'email'];
        }
        $length = mb_strlen($message);
        if ($length < self::MIN_LENGTH) {
            return ['ok' => false, 'field' => 'message', 'error' => 'short'];
        }
        if ($length > self::MAX_LENGTH) {
            return ['ok' => false, 'field' => 'message', 'error' => 'long'];
        }

        return ['ok' => true, 'name' => $name, 'email' => $email, 'message' => $message, 'includeShop' => !empty($input['includeShop'])];
    }

    /**
     * The JSON the contact service receives.
     *
     * @param array{name: string, email: string, message: string, includeShop: bool} $valid
     * @param list<string> $versions
     * @return array<string, mixed>
     */
    public static function payload(array $valid, bool $pro, array $versions, string $shop): array
    {
        return [
            'topic' => 'support',
            'name' => $valid['name'],
            'email' => $valid['email'],
            'message' => $valid['message'],
            'app' => [
                'product' => $pro ? 'shopware-pro' : 'shopware',
                'versions' => implode(', ', $versions),
                'shop' => $valid['includeShop'] ? $shop : '',
            ],
        ];
    }

    /**
     * Post it. Returns null when it went, or the contact service's own reason (or '' when the
     * service could not be reached).
     *
     * @param array<string, mixed> $payload
     */
    public function send(array $payload): ?string
    {
        $body = (string) json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        try {
            $response = $this->client->request('POST', self::ENDPOINT, [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    // The contact service sits behind CloudFront, which needs the body's hash to sign the request.
                    'x-amz-content-sha256' => hash('sha256', $body),
                ],
                'timeout' => 15,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($response === null) {
                return '';
            }
        } catch (GuzzleException) {
            return '';
        }
        if ($response->getStatusCode() === 200) {
            return null;
        }
        $answer = json_decode((string) $response->getBody(), true);

        return \is_array($answer) && \is_string($answer['message'] ?? null) ? self::oneLine($answer['message'], 300) : '';
    }

    /** The shop's host name, shown next to the "include my shop address" tick. */
    public static function shopHost(string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) ? $host : '';
    }

    private static function oneLine(mixed $value, int $max): string
    {
        return \is_string($value) ? mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value)), 0, $max) : '';
    }
}
