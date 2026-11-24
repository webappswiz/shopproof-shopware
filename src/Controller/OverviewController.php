<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Setup\OverviewData;
use Shopproof\Compliance\Setup\Recommended;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the top of the Shopproof page: the setup checklist and the status cards
 * (Overview). Reads only the shop's own database; nothing leaves the shop.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class OverviewController extends AbstractController
{
    public function __construct(
        private readonly OverviewData $overview,
        private readonly Recommended $recommended,
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/overview',
        name: 'api.action.shopproof.overview',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function overview(Request $request): JsonResponse
    {
        return new JsonResponse($this->overview->build(self::zone($request->query->get('timezone'))));
    }

    /**
     * The press: switches on what the shop's facts call for right now (read again here, never
     * taken from the request).
     */
    #[Route(
        path: '/api/_action/shopproof/recommended/apply',
        name: 'api.action.shopproof.recommended.apply',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function applyRecommended(): JsonResponse
    {
        $done = $this->recommended->apply(Recommended::plan($this->recommended->facts()), time());

        return new JsonResponse(['switched' => $done]);
    }

    #[Route(
        path: '/api/_action/shopproof/recommended/undo',
        name: 'api.action.shopproof.recommended.undo',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function undoRecommended(): JsonResponse
    {
        return new JsonResponse(['undone' => $this->recommended->undo()]);
    }

    private static function zone(mixed $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone(\is_string($name) && \in_array($name, \DateTimeZone::listIdentifiers(), true) ? $name : 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
