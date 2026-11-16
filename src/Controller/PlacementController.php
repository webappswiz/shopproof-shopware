<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Placement\PlacementPages;
use Shopproof\Compliance\Placement\PlacementResult;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the placement check on the Shopproof page (decision 0024): the shop pages to open
 * (with preview keys), the labels to look for, and the last result as counts.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class PlacementController extends AbstractController
{
    public function __construct(
        private readonly PlacementPages $pages,
        private readonly SystemConfigService $config,
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/placement',
        name: 'api.action.shopproof.placement',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function pages(Request $request): JsonResponse
    {
        $response = new JsonResponse([
            ...$this->pages->collect(time(), $request->getSchemeAndHttpHost()),
            'last' => PlacementResult::read($this->config->get(PlacementResult::CONFIG)),
        ]);
        // The page addresses carry preview keys: never cached anywhere.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route(
        path: '/api/_action/shopproof/placement/result',
        name: 'api.action.shopproof.placement.result',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function result(Request $request): Response
    {
        $body = json_decode($request->getContent(), true);
        $this->config->set(PlacementResult::CONFIG, PlacementResult::clean(\is_array($body) ? $body : [], time()));

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
