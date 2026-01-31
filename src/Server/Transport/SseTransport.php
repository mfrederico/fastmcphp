<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Transport;

use Fastmcphp\Protocol\JsonRpc;
use Fastmcphp\Protocol\JsonRpcException;
use Fastmcphp\Protocol\ErrorCodes;
use Fastmcphp\Server\Server;
use Fastmcphp\Server\Auth\AuthRequest;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Table;
use Swoole\Coroutine;

/**
 * SSE (Server-Sent Events) transport for MCP.
 *
 * Provides bidirectional communication:
 * - GET /sse - SSE stream for server-to-client messages
 * - POST /message - Client-to-server messages
 */
class SseTransport implements TransportInterface
{
    private ?SwooleHttpServer $httpServer = null;
    private ?Table $sessions = null;
    /** @var array<string, array> Session auth context (in-memory) */
    private array $sessionAuth = [];

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8080,
        private readonly string $ssePath = '/sse',
        private readonly string $messagePath = '/message',
    ) {}

    public function run(Server $server): void
    {
        // Create a shared table for session management
        $this->sessions = new Table(1024);
        $this->sessions->column('response_fd', Table::TYPE_INT);
        $this->sessions->column('created_at', Table::TYPE_INT);
        $this->sessions->column('token', Table::TYPE_STRING, 128);
        $this->sessions->column('workspace', Table::TYPE_STRING, 64);
        $this->sessions->create();

        $this->httpServer = new SwooleHttpServer($this->host, $this->port);

        // Get CPU count - compatible with both Swoole and OpenSwoole
        $cpuNum = 4; // sensible default
        if (function_exists('swoole_cpu_num')) {
            $cpuNum = swoole_cpu_num();
        } elseif (class_exists('OpenSwoole\Util')) {
            $cpuNum = \OpenSwoole\Util::getCPUNum();
        }

        $this->httpServer->set([
            'worker_num' => $cpuNum,
            'enable_coroutine' => true,
        ]);

        $this->httpServer->on('start', function (SwooleHttpServer $http) {
            echo "Fastmcphp SSE server started at http://{$this->host}:{$this->port}\n";
            echo "  SSE endpoint: {$this->ssePath}\n";
            echo "  Message endpoint: {$this->messagePath}\n";
        });

        $this->httpServer->on('request', function (Request $request, Response $response) use ($server) {
            $this->handleRequest($server, $request, $response);
        });

        $this->httpServer->start();
    }

    /**
     * Handle an HTTP request.
     */
    private function handleRequest(Server $server, Request $request, Response $response): void
    {
        // Set CORS headers
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-TOKEN');

        // Handle preflight
        if ($request->server['request_method'] === 'OPTIONS') {
            $response->status(204);
            $response->end();
            return;
        }

        $path = $request->server['request_uri'] ?? '/';
        $method = $request->server['request_method'];

        // Health check
        if ($path === '/health') {
            $response->header('Content-Type', 'application/json');
            $response->status(200);
            $response->end(json_encode(['status' => 'ok']));
            return;
        }

        // SSE endpoint
        if ($path === $this->ssePath && $method === 'GET') {
            $this->handleSse($server, $request, $response);
            return;
        }

        // Message endpoint
        if ($path === $this->messagePath && $method === 'POST') {
            $this->handleMessage($server, $request, $response);
            return;
        }

        $response->status(404);
        $response->end('Not Found');
    }

    /**
     * Handle SSE connection.
     */
    private function handleSse(Server $server, Request $request, Response $response): void
    {
        // Generate session ID
        $sessionId = bin2hex(random_bytes(16));

        // Set SSE headers
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        // Extract token and workspace from SSE connection
        $token = $request->get['token'] ?? $request->header['x-api-token'] ?? '';
        $workspace = $request->get['workspace'] ?? $request->header['x-workspace'] ?? 'default';

        // Store session with auth context in shared table (accessible across workers)
        $this->sessions->set($sessionId, [
            'response_fd' => $response->fd,
            'created_at' => time(),
            'token' => $token,
            'workspace' => $workspace,
        ]);

        // Also store in local array for this worker (for query params injection)
        $this->sessionAuth[$sessionId] = [
            'query' => $request->get ?? [],
            'headers' => $request->header ?? [],
        ];

        // Send initial event with session ID
        // Use Host header if available, otherwise fall back to localhost (not 0.0.0.0)
        $clientHost = $request->header['host'] ?? "localhost:{$this->port}";
        // Remove port from host header if it's already there, then add our port
        $hostWithoutPort = preg_replace('/:\d+$/', '', $clientHost);
        $messageUri = "http://{$hostWithoutPort}:{$this->port}{$this->messagePath}?sessionId={$sessionId}";

        $this->sendSseEvent($response, 'endpoint', json_encode([
            'uri' => $messageUri,
        ]));

        // Keep connection alive
        while (true) {
            Coroutine::sleep(15);

            // Check if connection is still alive (compatible with Swoole and OpenSwoole)
            try {
                // Try to get connection info - returns false/null if disconnected
                $info = method_exists($this->httpServer, 'exist')
                    ? $this->httpServer->exist($response->fd)
                    : $this->httpServer->getClientInfo($response->fd);

                if (!$info) {
                    break;
                }

                // Send heartbeat
                $this->sendSseEvent($response, 'ping', json_encode(['time' => time()]));
            } catch (\Throwable $e) {
                // Connection closed
                break;
            }
        }

        // Cleanup session
        $this->sessions->del($sessionId);
    }

    /**
     * Send an SSE event.
     */
    private function sendSseEvent(Response $response, string $event, string $data): void
    {
        $message = "event: {$event}\ndata: {$data}\n\n";
        $response->write($message);
    }

    /**
     * Handle message POST.
     */
    private function handleMessage(Server $server, Request $request, Response $response): void
    {
        $sessionId = $request->get['sessionId'] ?? null;

        // Check session exists and get stored auth data from shared table
        $sessionData = $sessionId !== null ? $this->sessions->get($sessionId) : false;
        if ($sessionData === false) {
            $response->status(400);
            $response->end(json_encode(['error' => 'Invalid or missing session ID']));
            return;
        }

        $body = $request->getContent();

        if (empty($body)) {
            $response->header('Content-Type', 'application/json');
            $response->status(400);
            $response->end(JsonRpc::encodeError(
                null,
                ErrorCodes::INVALID_REQUEST,
                'Empty request body'
            ));
            return;
        }

        try {
            $message = JsonRpc::parse($body);

            // Build auth context from shared table (works across workers)
            // Note: AuthRequest::getToken() checks 'key' query param, so use that
            $authParams = [];
            if (!empty($sessionData['token'])) {
                $authParams['key'] = $sessionData['token'];  // 'key' is what AuthRequest expects
                $authParams['token'] = $sessionData['token']; // Also keep as 'token' for compatibility
            }
            if (!empty($sessionData['workspace'])) {
                $authParams['workspace'] = $sessionData['workspace'];
            }

            // Create auth request and inject session's auth context
            $authRequest = AuthRequest::fromSwoole($request);
            if (!empty($authParams)) {
                $authRequest = $authRequest->withQueryParams($authParams);
            }

            $result = $server->handle($message, $authRequest);

            $response->header('Content-Type', 'application/json');
            $response->status(200);
            $response->end($result ?? '');
        } catch (JsonRpcException $e) {
            $response->header('Content-Type', 'application/json');
            $response->status(200);
            $response->end(JsonRpc::encodeError(
                null,
                $e->getCode(),
                $e->getMessage(),
                $e->data
            ));
        } catch (\Throwable $e) {
            $response->header('Content-Type', 'application/json');
            $response->status(500);
            $response->end(JsonRpc::encodeError(
                null,
                ErrorCodes::INTERNAL_ERROR,
                'Internal error: ' . $e->getMessage()
            ));
        }
    }

    public function stop(): void
    {
        $this->httpServer?->shutdown();
    }
}
