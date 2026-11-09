<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Withdrawal\OrderLink;
use Shopproof\Compliance\Withdrawal\WithdrawalStore;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The customer's withdrawal status page: received, return arrived, refund sent. The status
 * emails link to it with the record's id and a signature; nothing else opens it. Inside the shop's
 * theme, no script, not cached, not indexed, and the link is not passed on in the Referer header.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final class WithdrawalStatusController extends StorefrontController
{
    public function __construct(
        private readonly GenericPageLoaderInterface $pages,
        private readonly WithdrawalStore $store,
        private readonly OrderLink $link,
    ) {
    }

    #[Route(
        path: '/shopproof/withdrawal-status/{id}/{key}',
        name: 'frontend.shopproof.withdrawal.status',
        requirements: ['id' => '[0-9a-f]{32}', 'key' => '[0-9a-f]{32}'],
        methods: ['GET']
    )]
    public function status(string $id, string $key, Request $request, SalesChannelContext $context): Response
    {
        $record = $this->link->verifyStatus($id, $key) ? $this->store->find($id) : null;
        if ($record !== null && $record['salesChannelId'] !== $context->getSalesChannelId()) {
            $record = null;
        }
        $response = $this->renderStorefront('@ShopproofCompliance/storefront/page/shopproof/withdrawal-status.html.twig', [
            'page' => $this->pages->load($request, $context),
            'shopproofStatus' => $record === null ? null : [
                'contractNumber' => $record['contractNumber'],
                'submittedAt' => $record['submittedAt'],
                'steps' => [
                    ['key' => 'received', 'done' => true, 'at' => $record['submittedAt']],
                    ['key' => 'return_arrived', 'done' => $record['returnArrivedAt'] !== '', 'at' => $record['returnArrivedAt']],
                    ['key' => 'refund_sent', 'done' => $record['refundSentAt'] !== '', 'at' => $record['refundSentAt']],
                ],
                'current' => $record['status'],
            ],
        ]);
        $response->setStatusCode($record === null ? Response::HTTP_NOT_FOUND : Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
