<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Withdrawal\OrderEmailLine;
use Shopproof\Compliance\Withdrawal\SetupCheck;
use Shopproof\Compliance\Withdrawal\SetupFacts;
use Shopproof\Compliance\Withdrawal\WithdrawalClock;
use Shopproof\Compliance\Withdrawal\WithdrawalStatus;
use Shopproof\Compliance\Withdrawal\WithdrawalStore;
use Shopproof\Compliance\Withdrawal\WithdrawalSubscriber;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SystemConfig\SystemConfigService;
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
        private readonly SystemConfigService $config,
        private readonly WithdrawalStatus $status,
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

    /**
     * "I have checked these" on the notice about requests that could not be recorded.
     */
    #[Route(
        path: '/api/_action/shopproof/withdrawal-failures',
        name: 'api.action.shopproof.withdrawal_failures.clear',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['DELETE']
    )]
    public function clearFailures(): Response
    {
        $this->config->delete(WithdrawalSubscriber::FAILURES);

        return new Response('', Response::HTTP_NO_CONTENT);
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
        $zone = $request->query->get('timezone');

        return new JsonResponse($this->store->list(
            $request->query->getInt('page', 1),
            $request->query->getInt('limit', 25),
            \is_string($term) ? mb_substr($term, 0, 255) : '',
            new \DateTimeZone(\is_string($zone) && \in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC'),
            OrderEmailLine::period($this->config->getInt('ShopproofCompliance.config.withdrawalDays'))
        ));
    }

    /**
     * Moves a withdrawal to received, return arrived or refund sent. Moving forward emails the
     * customer unless notify is false or the setting is off.
     */
    #[Route(
        path: '/api/_action/shopproof/withdrawals/{id}/status',
        name: 'api.action.shopproof.withdrawals.status',
        requirements: ['id' => '[0-9a-f]{32}'],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['order:update']],
        methods: ['POST']
    )]
    public function setStatus(string $id, Request $request): JsonResponse
    {
        $state = $request->request->get('status');
        if (!\is_string($state) || !\in_array($state, WithdrawalStore::STATES, true)) {
            return new JsonResponse(['error' => 'status must be one of ' . implode(', ', WithdrawalStore::STATES)], Response::HTTP_BAD_REQUEST);
        }
        if ($this->store->find($id) === null) {
            return new JsonResponse(['changed' => false], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->status->set($id, $state, $request->request->getBoolean('notify', true)));
    }

    /**
     * The day the goods arrived, as the merchant knows it ('' clears it), for the window.
     */
    #[Route(
        path: '/api/_action/shopproof/withdrawals/{id}/delivered',
        name: 'api.action.shopproof.withdrawals.delivered',
        requirements: ['id' => '[0-9a-f]{32}'],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['order:update']],
        methods: ['POST']
    )]
    public function setDelivered(string $id, Request $request): Response
    {
        $day = $request->request->get('deliveredOn');
        $day = \is_string($day) ? $day : '';
        if ($day !== '' && WithdrawalClock::cleanDate($day) === '') {
            return new JsonResponse(['error' => 'deliveredOn must be a date (YYYY-MM-DD) or empty'], Response::HTTP_BAD_REQUEST);
        }

        return $this->store->setDelivered($id, $day) ? new Response('', Response::HTTP_NO_CONTENT) : new JsonResponse(['changed' => false], Response::HTTP_NOT_FOUND);
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
