<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Withdrawal\WithdrawalItems;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The item picker asks here for the items of the order the customer named on Shopware's
 * withdrawal form. Public like the form, never cached, a few lookups per connection an hour, and
 * the same 404 for every order that does not fit.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final class WithdrawalItemsController
{
    public function __construct(private readonly WithdrawalItems $items)
    {
    }

    #[Route(
        path: '/shopproof/withdrawal-items',
        name: 'frontend.shopproof.withdrawal.items',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function items(Request $request, SalesChannelContext $context): JsonResponse
    {
        $channel = $context->getSalesChannelId();
        if (!$this->items->pickerOn($channel)) {
            return self::answer(['items' => []], Response::HTTP_NOT_FOUND);
        }
        if (!$this->items->withinRateLimit((string) $request->getClientIp())) {
            return self::answer(['items' => []], Response::HTTP_TOO_MANY_REQUESTS);
        }
        $input = json_decode($request->getContent(), true);
        $order = \is_array($input) ? $this->items->orderFor($input, $channel) : '';
        $list = $order !== '' ? $this->items->items($order, $channel, $context->getContext()) : [];
        if ($list === []) {
            return self::answer(['items' => []], Response::HTTP_NOT_FOUND);
        }
        $body = ['items' => $list];
        if (\in_array(true, array_column($list, 'excluded'), true)) {
            $body['excludedNote'] = $this->items->note($channel);
        }

        return self::answer($body, Response::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function answer(array $body, int $status): JsonResponse
    {
        $response = new JsonResponse($body, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
