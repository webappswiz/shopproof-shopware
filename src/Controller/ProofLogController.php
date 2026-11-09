<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\ProofLog\ProofChain;
use Shopproof\Compliance\ProofLog\ProofLog;
use Shopproof\Compliance\ProofLog\ProofSnapshot;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin API for the proof log on the Shopproof page: the newest entries, the chain check, a
 * snapshot on demand and the CSV download. Everything stays on the shop's server.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class ProofLogController extends AbstractController
{
    public function __construct(
        private readonly ProofLog $log,
        private readonly ProofSnapshot $snapshot,
    ) {
    }

    #[Route(
        path: '/api/_action/shopproof/proof-log',
        name: 'api.action.shopproof.proof_log',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function list(Request $request): JsonResponse
    {
        if (!$this->log->exists()) {
            return new JsonResponse(['available' => false]);
        }
        $page = $this->log->page($request->query->getInt('page', 1), $request->query->getInt('limit', 25));

        return new JsonResponse([
            'available' => true,
            'total' => $page['total'],
            'items' => $page['items'],
            'lastSnapshot' => $this->log->lastTime('snapshot'),
            'retentionDays' => $this->snapshot->retentionDays(),
        ]);
    }

    #[Route(
        path: '/api/_action/shopproof/proof-log/verify',
        name: 'api.action.shopproof.proof_log.verify',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['POST']
    )]
    public function verify(): JsonResponse
    {
        return new JsonResponse($this->log->verifyAll());
    }

    #[Route(
        path: '/api/_action/shopproof/proof-log/snapshot',
        name: 'api.action.shopproof.proof_log.snapshot',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function snapshot(): JsonResponse
    {
        $this->snapshot->run();

        return new JsonResponse(['lastSnapshot' => $this->log->lastTime('snapshot')]);
    }

    #[Route(
        path: '/api/_action/shopproof/proof-log/csv',
        name: 'api.action.shopproof.proof_log.csv',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function csv(): StreamedResponse
    {
        $response = new StreamedResponse(function (): void {
            echo ProofChain::csvLine(['id', 'created_at', 'type', 'summary', 'prev_hash', 'hash']);
            $after = 0;
            do {
                $rows = $this->log->rows($after);
                foreach ($rows as $row) {
                    echo ProofChain::csvLine([(string) $row['id'], $row['created_at'], $row['type'], $row['summary'], $row['prev_hash'], $row['hash']]);
                    $after = $row['id'];
                }
            } while ($rows !== []);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="shopproof-proof-log-' . gmdate('Y-m-d') . '.csv"');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
