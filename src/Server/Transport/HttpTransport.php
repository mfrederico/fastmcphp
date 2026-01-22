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

/**
 * HTTP transport for MCP - uses Swoole HTTP server.
 *
 * This transport provides a JSON-RPC over HTTP endpoint for MCP.
 */
class HttpTransport implements TransportInterface
{
    private ?SwooleHttpServer $httpServer = null;

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8080,
        private readonly string $path = '/mcp',
    ) {}

    public function run(Server $server): void
    {
        $this->httpServer = new SwooleHttpServer($this->host, $this->port);

        $this->httpServer->set([
            'worker_num' => swoole_cpu_num(),
            'enable_coroutine' => true,
        ]);

        $this->httpServer->on('start', function (SwooleHttpServer $http) {
            echo "Fastmcphp HTTP server started at http://{$this->host}:{$this->port}{$this->path}\n";
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
        $response->header('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-TOKEN');

        // Handle preflight
        if ($request->server['request_method'] === 'OPTIONS') {
            $response->status(204);
            $response->end();
            return;
        }

        // Only accept POST to the MCP path
        $path = $request->server['request_uri'] ?? '/';
        if ($path !== $this->path && $path !== $this->path . '/') {
            // Health check endpoint
            if ($path === '/health') {
                $response->header('Content-Type', 'application/json');
                $response->status(200);
                $response->end(json_encode(['status' => 'ok']));
                return;
            }

            $response->status(404);
            $response->end('Not Found');
            return;
        }

        if ($request->server['request_method'] !== 'POST') {
            $response->status(405);
            $response->end('Method Not Allowed');
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
            $response->status(200); // JSON-RPC errors still return 200
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
