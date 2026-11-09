<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Withdrawal\ReturnsWebhook;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the returns tool card on the Shopproof page: the signing secret (so the shop can
 * paste it into its returns tool), a new secret, and a test delivery.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class ReturnsController extends AbstractController
{
    public function __construct(private readonly ReturnsWebhook $webhook)
    {
    }

    #[Route(
        path: '/api/_action/shopproof/returns',
        name: 'api.action.shopproof.returns',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function show(Request $request): JsonResponse
    {
        $channel = self::channel($request->query->get('salesChannelId'));

        return new JsonResponse([
            'webhookOn' => $this->webhook->url($channel) !== '',
            'webhookRejected' => $this->webhook->urlRejected($channel),
            'portalOn' => $this->webhook->portalUrl($channel) !== '',
            'secret' => $this->webhook->secret(),
        ]);
    }

    #[Route(
        path: '/api/_action/shopproof/returns/secret',
        name: 'api.action.shopproof.returns.secret',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function renew(): JsonResponse
    {
        return new JsonResponse(['secret' => $this->webhook->secret(true)]);
    }

    #[Route(
        path: '/api/_action/shopproof/returns/test',
        name: 'api.action.shopproof.returns.test',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function test(Request $request): JsonResponse
    {
        return new JsonResponse($this->webhook->sendTest(self::channel($request->request->get('salesChannelId'))));
    }

    private static function channel(mixed $value): ?string
    {
        return \is_string($value) && Uuid::isValid($value) ? strtolower($value) : null;
    }
}
