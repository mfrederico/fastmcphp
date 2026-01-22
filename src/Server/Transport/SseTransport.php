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
        $this->sessions->create();

        $this->httpServer = new SwooleHttpServer($this->host, $this->port);

        $this->httpServer->set([
            'worker_num' => swoole_cpu_num(),
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

        // Store session
        $this->sessions->set($sessionId, [
            'response_fd' => $response->fd,
            'created_at' => time(),
        ]);

        // Send initial event with session ID
        $this->sendSseEvent($response, 'endpoint', json_encode([
            'uri' => "http://{$this->host}:{$this->port}{$this->messagePath}?sessionId={$sessionId}",
        ]));

        // Keep connection alive
        while (true) {
            // Send heartbeat
            $this->sendSseEvent($response, 'ping', json_encode(['time' => time()]));

            Coroutine::sleep(15);

            // Check if connection is still alive
            if (!$this->httpServer->exist($response->fd)) {
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

        if ($sessionId === null || !$this->sessions->exist($sessionId)) {
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

            // Create auth request from HTTP request
            $authRequest = AuthRequest::fromSwoole($request);

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
