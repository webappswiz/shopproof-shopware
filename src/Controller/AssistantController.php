<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Assistant\AssistantStore;
use Shopproof\Compliance\Assistant\AssistantTools;
use Shopproof\Compliance\Assistant\McpServer;
use Shopproof\Compliance\ProofLog\ProofLog;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * AI assistant connection (decision 0047): the MCP endpoint the shop owner's own AI assistant
 * calls, and the admin API behind the "AI assistant" card on the Shopproof page.
 *
 * The assistant calls this shop directly at /api/shopproof/mcp with a connection key the owner
 * made on the card (Authorization: Bearer). Nothing is sent to Shopproof or anyone else by the
 * plugin. Off until the owner turns it on; read only until "Allow changes"; customer names and
 * emails only with "Include customer details". Keys stop after 90 days and can be removed.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class AssistantController extends AbstractController
{
    public const PATH = '/api/shopproof/mcp';

    public function __construct(
        private readonly AssistantStore $store,
        private readonly AssistantTools $tools,
        private readonly ProofLog $log,
        private readonly string $contractFile,
        private readonly string $version = '',
    ) {
    }

    /**
     * The MCP endpoint. Shopware's own admin sign-in is not used here (an assistant cannot do
     * its OAuth steps), so the route checks the Shopproof connection key itself.
     */
    #[Route(path: self::PATH, name: 'api.shopproof.mcp', defaults: ['auth_required' => false], methods: ['POST'])]
    public function mcp(Request $request): Response
    {
        $switches = $this->store->switches();
        if (!$switches['on']) {
            return self::plain(403, 'The AI assistant connection is turned off in Shopproof.');
        }
        $key = $this->user($request);
        if ($key === null) {
            $response = self::plain(401, 'Use a current Shopproof connection key of an admin user who may still see Shopproof\'s settings. Create one on the Shopproof page, card "AI assistant".');
            $response->headers->set('WWW-Authenticate', 'Bearer realm="Shopproof"');

            return $response;
        }
        $raw = (string) $request->getContent();
        $wait = $this->store->overLimit($key['key'], McpServer::calls($raw), time());
        if ($wait > 0) {
            $response = self::plain(429, 'This key made more than ' . AssistantStore::CALLS_PER_HOUR . ' calls in the last hour. Try again later.');
            $response->headers->set('Retry-After', (string) $wait);

            return $response;
        }
        $user = $key['user'];
        $rights = $key['rights'];
        $headers = [];
        foreach (['mcp-protocol-version', 'mcp-method', 'mcp-name'] as $name) {
            $value = $request->headers->get($name);
            if (\is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }
        $server = new McpServer(McpServer::contract($this->contractFile), $this->version);
        $out = $server->handle(
            $raw,
            fn (string $name, array $args): array => $this->tools->call($name, $args, $switches, $user, $rights),
            $headers
        );
        // A notification gets 202 Accepted with no body (MCP Streamable HTTP).
        $response = $out['body'] === null ? new Response('', Response::HTTP_ACCEPTED) : new JsonResponse($out['body'], $out['status']);
        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** Streamable HTTP clients may open a GET stream; this server has nothing to push. */
    #[Route(path: self::PATH, name: 'api.shopproof.mcp.stream', defaults: ['auth_required' => false], methods: ['GET', 'DELETE'])]
    public function noStream(): Response
    {
        $response = self::plain(405, 'This address only takes POST requests.');
        $response->headers->set('Allow', 'POST');

        return $response;
    }

    #[Route(
        path: '/api/_action/shopproof/assistant',
        name: 'api.action.shopproof.assistant',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']],
        methods: ['GET']
    )]
    public function state(Request $request): JsonResponse
    {
        return new JsonResponse([
            'switches' => $this->store->switches(),
            'keys' => $this->store->keys(time()),
            'changes' => $this->store->changes(20),
            'endpoint' => self::endpoint($request),
            'keyDays' => AssistantStore::KEY_DAYS,
        ]);
    }

    #[Route(
        path: '/api/_action/shopproof/assistant/switches',
        name: 'api.action.shopproof.assistant.switches',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function saveSwitches(Request $request): JsonResponse
    {
        $before = $this->store->switches();
        $this->store->saveSwitches(
            $request->request->getBoolean('on'),
            $request->request->getBoolean('changes'),
            $request->request->getBoolean('details')
        );
        $after = $this->store->switches();
        // The owner's own choices are part of the record too; without the entry they are not saved.
        if ($after !== $before && !$this->log->append('assistant', ['by' => 'owner', 'switches' => $after])) {
            $this->store->saveSwitches($before['on'], $before['changes'], $before['details']);

            return new JsonResponse(['error' => 'log', 'switches' => $before], 500);
        }

        return new JsonResponse(['switches' => $after]);
    }

    #[Route(
        path: '/api/_action/shopproof/assistant/keys',
        name: 'api.action.shopproof.assistant.keys.create',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function createKey(Request $request, Context $context): JsonResponse
    {
        $source = $context->getSource();
        $user = $source instanceof AdminApiSource ? $source->getUserId() : null;
        if (!$this->store->switches()['on'] || $user === null) {
            return new JsonResponse(['error' => $user === null ? 'user' : 'off'], 400);
        }
        $made = $this->store->createKey($user, time());
        $endpoint = self::endpoint($request);

        $response = new JsonResponse([
            ...$made,
            'endpoint' => $endpoint,
            'clientConfig' => McpServer::clientConfig($endpoint, $made['key']),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route(
        path: '/api/_action/shopproof/assistant/keys/{id}',
        name: 'api.action.shopproof.assistant.keys.remove',
        requirements: ['id' => '[0-9a-f]{16}'],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['DELETE']
    )]
    public function removeKey(string $id): JsonResponse
    {
        return new JsonResponse(['removed' => $this->store->removeKey($id)]);
    }

    /** The owner's Undo button on the card: the same undo as the assistant's undo_change. */
    #[Route(
        path: '/api/_action/shopproof/assistant/changes/{id}/undo',
        name: 'api.action.shopproof.assistant.undo',
        requirements: ['id' => '\d{8}-\d{6}-[A-Za-z0-9]{4}'],
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']],
        methods: ['POST']
    )]
    public function undo(string $id): JsonResponse
    {
        $done = $this->tools->undoChange($id, 'owner');

        return new JsonResponse($done, $done['ok'] ? 200 : 409);
    }

    /**
     * @return array{user: string, key: string, rights: array{settings: bool, change: bool, orders: bool}}|null
     */
    private function user(Request $request): ?array
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) !== 1) {
            return null;
        }

        return $this->store->userForKey($m[1], time());
    }

    private static function endpoint(Request $request): string
    {
        return $request->getSchemeAndHttpHost() . $request->getBasePath() . self::PATH;
    }

    private static function plain(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status, ['Cache-Control' => 'no-store']);
    }
}
