<?php declare(strict_types=1);

namespace Shopproof\Compliance\Assistant;

/**
 * The Model Context Protocol (MCP) answers of the AI assistant connection (decision 0047).
 *
 * Streamable HTTP, stateless and JSON only: every POST gets one JSON answer, there is no session
 * and no event stream. It speaks the 2026-07-28 revision (no handshake; version, method and tool
 * name also in headers, `server/discover`) and the older revisions back to 2024-11-05
 * (initialize first): the same rules as the public server in packages/mcp/src/server.mjs and the
 * WooCommerce plugin's Shopproof_Assistant::rpc(). Pure apart from the tool callback, so it is
 * unit tested alone.
 */
final class McpServer
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const HEADER_MISMATCH = -32020;

    public const UNSUPPORTED_VERSION = -32022;

    /** The protocol revision without a handshake, and the request key that carries the version. */
    public const MODERN = '2026-07-28';

    public const META_VERSION = 'io.modelcontextprotocol/protocolVersion';

    /** Largest request body, in bytes. */
    public const MAX_BODY = 65536;

    public const DISCLAIMER = 'Technical help, not legal advice. Shopproof never calls a shop compliant.';

    public const INSTRUCTIONS = 'Shopproof helps this Shopware shop with EU shop rules. Start with get_setup_status. Change settings only after showing the owner the lines from preview_change and getting a clear yes, then call apply_change. Every change can be undone with undo_change. Shopproof gives technical help, not legal advice; never call the shop compliant.';

    /**
     * @param array{version: int, protocolVersions: list<string>, tools: list<array{name: string, kind: string, title: string, description: string, inputSchema: array<string, mixed>}>} $contract
     */
    public function __construct(
        private readonly array $contract,
        private readonly string $version,
    ) {
    }

    /**
     * The shared tool list, read from the plugin's copy of packages/assistant/tools.json.
     *
     * @return array{version: int, protocolVersions: list<string>, tools: list<array{name: string, kind: string, title: string, description: string, inputSchema: array<string, mixed>}>}
     */
    public static function contract(string $file): array
    {
        $json = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $json = \is_array($json) ? $json : [];
        $tools = [];
        foreach (\is_array($json['tools'] ?? null) ? $json['tools'] : [] as $tool) {
            if (\is_array($tool) && \is_string($tool['name'] ?? null) && \is_string($tool['kind'] ?? null) && \is_string($tool['title'] ?? null)
                && \is_string($tool['description'] ?? null) && \is_array($tool['inputSchema'] ?? null)) {
                $schema = [];
                foreach ($tool['inputSchema'] as $field => $value) {
                    // PHP reads "properties": {} as an empty array, which would go out as [].
                    $schema[(string) $field] = $field === 'properties' && $value === [] ? new \stdClass() : $value;
                }
                $tools[] = ['name' => $tool['name'], 'kind' => $tool['kind'], 'title' => $tool['title'], 'description' => $tool['description'], 'inputSchema' => $schema];
            }
        }
        $versions = \is_array($json['protocolVersions'] ?? null) ? array_values(array_filter($json['protocolVersions'], 'is_string')) : [];

        return [
            'version' => is_numeric($json['version'] ?? null) ? (int) $json['version'] : 0,
            'protocolVersions' => $versions,
            'tools' => $tools,
        ];
    }

    /**
     * Ready-to-paste client settings (the mcp-remote bridge with a sign-in header). Pure.
     */
    public static function clientConfig(string $endpoint, string $key): string
    {
        return (string) json_encode([
            'mcpServers' => [
                'shopproof' => [
                    'command' => 'npx',
                    'args' => ['-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${SHOPPROOF_AUTH}'],
                    'env' => ['SHOPPROOF_AUTH' => 'Bearer ' . $key],
                ],
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * How many calls a request counts for: each message of a batch counts. Pure, so it is unit
     * tested alone.
     */
    public static function calls(string $raw): int
    {
        $trimmed = ltrim($raw);
        if ($trimmed === '' || $trimmed[0] !== '[') {
            return 1;
        }
        $body = json_decode($trimmed, true, 64);

        return \is_array($body) ? max(1, \count($body)) : 1;
    }

    /**
     * Answers a raw request body.
     *
     * @param callable(string, array<mixed>): array{ok: bool, data?: mixed, error?: string} $call    runs a tool
     * @param array<string, string>                                                           $headers MCP headers, lower case names
     *
     * @return array{status: int, body: mixed} body null means no content (202)
     */
    public function handle(string $raw, callable $call, array $headers = []): array
    {
        if (\strlen($raw) > self::MAX_BODY) {
            return self::reply(413, self::error(null, self::INVALID_REQUEST, 'Request too large.'));
        }
        $body = json_decode($raw, true, 64);
        if ($body === null) {
            return self::reply(400, self::error(null, self::PARSE_ERROR, 'Parse error'));
        }

        return $this->rpc($body, $call, $headers);
    }

    /**
     * Answers a decoded request body.
     *
     * @param callable(string, array<mixed>): array{ok: bool, data?: mixed, error?: string} $call
     * @param array<string, string>                                                           $headers
     *
     * @return array{status: int, body: mixed}
     */
    public function rpc(mixed $body, callable $call, array $headers = []): array
    {
        // Batches existed only in 2025-03-26; answer them for older clients.
        if (\is_array($body) && $body !== [] && array_is_list($body)) {
            if (\count($body) > 20) {
                return self::reply(400, self::error(null, self::INVALID_REQUEST, 'Invalid batch.'));
            }
            $out = [];
            foreach ($body as $message) {
                if (!\is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
                    $out[] = self::error(null, self::INVALID_REQUEST, 'Invalid request.');
                    continue;
                }
                if (!isset($message['id']) || !\is_string($message['method'] ?? null)) {
                    continue;
                }
                $out[] = $this->message($message, $call, '2025-03-26')['body'];
            }

            return $out === [] ? self::reply(202, null) : self::reply(200, $out);
        }
        if (!\is_array($body) || ($body['jsonrpc'] ?? null) !== '2.0') {
            return self::reply(400, self::error(null, self::INVALID_REQUEST, 'Invalid request.'));
        }
        if (!isset($body['id']) || !\is_string($body['method'] ?? null)) {
            // Notifications and responses need no answer.
            $empty = !isset($body['method']) && !\array_key_exists('result', $body) && !\array_key_exists('error', $body);

            return $empty ? self::reply(400, self::error(null, self::INVALID_REQUEST, 'Invalid request.')) : self::reply(202, null);
        }
        $id = \is_string($body['id']) || \is_int($body['id']) ? $body['id'] : null;
        if ($body['method'] === 'initialize') {
            return $this->message($body, $call, 'legacy');
        }
        $supported = $this->contract['protocolVersions'];
        $header = $headers['mcp-protocol-version'] ?? '';
        $params = \is_array($body['params'] ?? null) ? $body['params'] : [];
        $metaList = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $meta = \is_string($metaList[self::META_VERSION] ?? null) ? $metaList[self::META_VERSION] : '';
        if ($header !== '' && $meta !== '' && $header !== $meta) {
            return self::reply(400, self::error($id, self::HEADER_MISMATCH, 'Header mismatch: MCP-Protocol-Version header value does not match body value'));
        }
        // Clients older than 2025-06-18 send no version header.
        $version = $header !== '' ? $header : ($meta !== '' ? $meta : '2025-03-26');
        if (!\in_array($version, $supported, true)) {
            return self::reply(400, self::error($id, self::UNSUPPORTED_VERSION, 'Unsupported protocol version', [
                'supported' => $supported,
                'requested' => substr($version, 0, 40),
            ]));
        }
        if ($version === self::MODERN) {
            $method = $headers['mcp-method'] ?? '';
            if ($method !== $body['method']) {
                return self::reply(400, self::error($id, self::HEADER_MISMATCH, $method === '' ? 'Missing Mcp-Method header' : 'Header mismatch: Mcp-Method header value does not match body value'));
            }
            if ($body['method'] === 'tools/call') {
                $name = $headers['mcp-name'] ?? '';
                $sent = \is_string($params['name'] ?? null) ? $params['name'] : '';
                if ($name !== $sent) {
                    return self::reply(400, self::error($id, self::HEADER_MISMATCH, $name === '' ? 'Missing Mcp-Name header' : 'Header mismatch: Mcp-Name header value does not match body value'));
                }
            }
        }

        return $this->message($body, $call, $version);
    }

    /**
     * @return array{name: string, title: string, version: string}
     */
    public function serverInfo(): array
    {
        return ['name' => 'shopproof-shopware', 'title' => 'Shopproof for Shopware', 'version' => $this->version];
    }

    /**
     * The initialize answer (revisions before 2026-07-28): the client's version when we know it,
     * else the newest of those.
     *
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    public function initialize(array $params): array
    {
        $known = array_values(array_diff($this->contract['protocolVersions'], [self::MODERN]));
        $asked = \is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';

        return [
            'protocolVersion' => \in_array($asked, $known, true) ? $asked : ($known[0] ?? '2025-06-18'),
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => $this->serverInfo(),
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    /**
     * Tools as MCP lists them.
     *
     * @return list<array<string, mixed>>
     */
    public function toolList(): array
    {
        $out = [];
        foreach ($this->contract['tools'] as $tool) {
            $out[] = [
                'name' => $tool['name'],
                'title' => $tool['title'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
                'annotations' => [
                    'readOnlyHint' => $tool['kind'] === 'read',
                    'destructiveHint' => false,
                    'idempotentHint' => $tool['kind'] === 'read',
                    'openWorldHint' => false,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param array<mixed>                                                                    $message request with an id and a method
     * @param callable(string, array<mixed>): array{ok: bool, data?: mixed, error?: string} $call
     *
     * @return array{status: int, body: mixed}
     */
    private function message(array $message, callable $call, string $version): array
    {
        $id = \is_string($message['id'] ?? null) || \is_int($message['id'] ?? null) ? $message['id'] : null;
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];
        $modern = $version === self::MODERN;
        $ok = function (array $result) use ($id, $modern): array {
            $result = ['resultType' => 'complete'] + $result;
            if ($modern) {
                $result['_meta'] = ['io.modelcontextprotocol/serverInfo' => $this->serverInfo()];
            }

            return self::reply(200, self::result($id, $result));
        };
        $method = \is_string($message['method'] ?? null) ? $message['method'] : '';

        switch ($method) {
            case 'initialize':
                return $ok($this->initialize($params));
            case 'server/discover':
                return $ok([
                    'supportedVersions' => $this->contract['protocolVersions'],
                    'capabilities' => ['tools' => new \stdClass()],
                    'instructions' => self::INSTRUCTIONS,
                    'ttlMs' => 3600000,
                    'cacheScope' => 'private',
                ]);
            case 'ping':
                return $ok([]);
            case 'tools/list':
                return $ok(['tools' => $this->toolList(), 'ttlMs' => 3600000, 'cacheScope' => 'private']);
            case 'tools/call':
                $name = \is_string($params['name'] ?? null) ? $params['name'] : '';
                if (!\in_array($name, array_column($this->contract['tools'], 'name'), true)) {
                    return self::reply(200, self::error($id, self::INVALID_PARAMS, 'Unknown tool: ' . substr($name, 0, 60)));
                }
                if (isset($params['arguments']) && (!\is_array($params['arguments']) || ($params['arguments'] !== [] && array_is_list($params['arguments'])))) {
                    return self::reply(200, self::error($id, self::INVALID_PARAMS, 'Tool arguments must be an object.'));
                }
                $args = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                $done = $call($name, $args);
                $good = !empty($done['ok']);
                $data = \array_key_exists('data', $done) ? $done['data'] : null;
                if (!$good) {
                    $data = ['error' => \is_string($done['error'] ?? null) ? $done['error'] : 'The tool did not run.'];
                }
                $data = \is_array($data) && $data !== [] && !array_is_list($data) ? $data : ['items' => $data];
                if ($good) {
                    $data['disclaimer'] = self::DISCLAIMER;
                }

                return $ok([
                    'content' => [[
                        'type' => 'text',
                        'text' => (string) json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_INVALID_UTF8_SUBSTITUTE),
                    ]],
                    'structuredContent' => $data,
                    'isError' => !$good,
                ]);
            default:
                return self::reply($modern ? 404 : 200, self::error($id, self::METHOD_NOT_FOUND, 'Method not found: ' . substr($method, 0, 60)));
        }
    }

    /**
     * @return array{status: int, body: mixed}
     */
    private static function reply(int $status, mixed $body): array
    {
        return ['status' => $status, 'body' => $body];
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(string|int|null $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>
     */
    private static function error(string|int|null $id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}
