<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Doctrine\DBAL\Connection;
use Shopproof\Compliance\Support\SupportMessage;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the "Help and contact" card: what the form starts with, and Send.
 * GET reads only the shop; POST is the only call that leaves the shop, and only on Send.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class ContactController extends AbstractController
{
    public function __construct(
        private readonly SupportMessage $support,
        private readonly Connection $connection,
        private readonly string $version = '',
        private readonly string $shopwareVersion = '',
        /** @var array<string, string> */
        private readonly array $bundles = [],
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/contact',
        name: 'api.action.shopproof.contact',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function form(Context $context): JsonResponse
    {
        $user = $this->user($context);

        return new JsonResponse([
            'name' => $user['name'],
            'email' => $user['email'],
            'shop' => SupportMessage::shopHost($this->shopUrl()),
            'versions' => $this->versions(),
            'pro' => $this->pro(),
            'address' => SupportMessage::EMAIL,
        ]);
    }

    #[Route(
        path: '/api/_action/shopproof/contact',
        name: 'api.action.shopproof.contact.send',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['POST']
    )]
    public function send(Request $request): JsonResponse
    {
        $input = $request->toArray();
        $valid = SupportMessage::validate($input);
        if ($valid['ok'] === false) {
            return new JsonResponse(['ok' => false, 'field' => $valid['field'], 'error' => $valid['error']], 400);
        }
        $reason = $this->support->send(SupportMessage::payload($valid, $this->pro(), $this->versions(), $this->shopUrl()));
        if ($reason !== null) {
            return new JsonResponse(['ok' => false, 'field' => '', 'error' => 'send', 'reason' => $reason], 502);
        }

        return new JsonResponse(['ok' => true, 'pro' => $this->pro()]);
    }

    /**
     * Version numbers that help us answer. Never shop, customer or order data.
     *
     * @return list<string>
     */
    private function versions(): array
    {
        $lines = ['Shopproof ' . $this->version];
        $pro = $this->proVersion();
        if ($pro !== '') {
            $lines[] = 'Shopproof Pro ' . $pro;
        }
        $lines[] = 'Shopware ' . $this->shopwareVersion;
        $lines[] = 'PHP ' . \PHP_VERSION;

        return $lines;
    }

    private function pro(): bool
    {
        return isset($this->bundles['ShopproofPro']);
    }

    private function proVersion(): string
    {
        if (!$this->pro()) {
            return '';
        }
        $version = $this->connection->fetchOne('SELECT version FROM plugin WHERE name = :name AND active = 1', ['name' => 'ShopproofPro']);

        return \is_string($version) ? $version : '';
    }

    /**
     * @return array{name: string, email: string}
     */
    private function user(Context $context): array
    {
        $source = $context->getSource();
        $id = $source instanceof AdminApiSource ? $source->getUserId() : null;
        if ($id === null || !Uuid::isValid($id)) {
            return ['name' => '', 'email' => ''];
        }
        $row = $this->connection->fetchAssociative(
            'SELECT first_name, last_name, email FROM user WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)]
        );
        if (!\is_array($row)) {
            return ['name' => '', 'email' => ''];
        }

        return [
            'name' => trim(self::text($row['first_name'] ?? null) . ' ' . self::text($row['last_name'] ?? null)),
            'email' => self::text($row['email'] ?? null),
        ];
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function shopUrl(): string
    {
        $url = $_SERVER['APP_URL'] ?? getenv('APP_URL');

        return \is_string($url) ? rtrim($url, '/') . '/' : '';
    }
}
