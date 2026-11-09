<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Withdrawal\SetupCheck;
use Shopproof\Compliance\Withdrawal\SetupFacts;
use Shopproof\Compliance\Withdrawal\WithdrawalStore;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the withdrawal part of the Shopproof page: the setup check for Shopware's own
 * withdrawal button and the record of requests sent through it.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class WithdrawalController extends AbstractController
{
    public function __construct(
        private readonly SetupFacts $facts,
        private readonly WithdrawalStore $store,
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/withdrawal-check',
        name: 'api.action.shopproof.withdrawal_check',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function check(): JsonResponse
    {
        return new JsonResponse(SetupCheck::evaluate($this->facts->collect()));
    }

    #[Route(
        path: '/api/_action/shopproof/withdrawals',
        name: 'api.action.shopproof.withdrawals',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['order:read']],
        methods: ['GET']
    )]
    public function list(Request $request): JsonResponse
    {
        $term = $request->query->get('term');

        return new JsonResponse($this->store->list(
            $request->query->getInt('page', 1),
            $request->query->getInt('limit', 25),
            \is_string($term) ? mb_substr($term, 0, 255) : ''
        ));
    }

    #[Route(
        path: '/api/_action/shopproof/withdrawals/{id}',
        name: 'api.action.shopproof.withdrawals.delete',
        requirements: ['id' => '[0-9a-f]{32}'],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['order:delete']],
        methods: ['DELETE']
    )]
    public function delete(string $id): Response
    {
        return $this->store->delete([$id]) > 0 ? new Response('', Response::HTTP_NO_CONTENT) : new JsonResponse(['deleted' => 0], Response::HTTP_NOT_FOUND);
    }
}
