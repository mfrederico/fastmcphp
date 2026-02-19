<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Transport;

use Fastmcphp\Protocol\JsonRpc;
use Fastmcphp\Protocol\JsonRpcException;
use Fastmcphp\Protocol\ErrorCodes;
use Fastmcphp\Server\Server;
use Fastmcphp\Server\Auth\AuthRequest;

/**
 * HTTP transport for MCP - JSON-RPC over HTTP.
 *
 * Uses Swoole/OpenSwoole when available for high-performance async handling,
 * or ReactPHP as a pure-PHP async alternative.
 */
class HttpTransport implements TransportInterface
{
    /** @var mixed Server instance (Swoole or ReactPHP, typed as mixed to avoid class resolution) */
    private mixed $httpServer = null;

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 8080,
        private readonly string $path = '/mcp',
    ) {}

    public function run(Server $server): void
    {
        if (extension_loaded('swoole') || extension_loaded('openswoole')) {
            $this->runWithSwoole($server);
        } else {
            $this->runWithReactPhp($server);
        }
    }

    /**
     * Run with Swoole/OpenSwoole HTTP server.
     */
    private function runWithSwoole(Server $server): void
    {
        $this->httpServer = new \Swoole\Http\Server($this->host, $this->port);

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

        $this->httpServer->on('start', function ($http) {
            echo "Fastmcphp HTTP server (Swoole) started at http://{$this->host}:{$this->port}{$this->path}\n";
        });

        $this->httpServer->on('request', function (\Swoole\Http\Request $request, \Swoole\Http\Response $response) use ($server) {
            $this->handleSwooleRequest($server, $request, $response);
        });

        $this->httpServer->start();
    }

    /**
     * Handle a Swoole HTTP request.
     */
    private function handleSwooleRequest(Server $server, \Swoole\Http\Request $request, \Swoole\Http\Response $response): void
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

    /**
     * Run with ReactPHP HTTP server.
     */
    private function runWithReactPhp(Server $server): void
    {
        $httpServer = new \React\Http\HttpServer(function (\Psr\Http\Message\ServerRequestInterface $request) use ($server) {
            return $this->handleReactRequest($server, $request);
        });

        $socket = new \React\Socket\SocketServer("{$this->host}:{$this->port}");
        $httpServer->listen($socket);

        $this->httpServer = $socket;

        echo "Fastmcphp HTTP server (ReactPHP) started at http://{$this->host}:{$this->port}{$this->path}\n";

        // ReactPHP runs its own event loop — this blocks until stopped
    }

    /**
     * Handle a ReactPHP HTTP request.
     */
    private function handleReactRequest(Server $server, \Psr\Http\Message\ServerRequestInterface $request): \React\Http\Message\Response
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-API-TOKEN',
        ];

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // Handle preflight
        if ($method === 'OPTIONS') {
            return new \React\Http\Message\Response(204, $corsHeaders);
        }

        // Health check
        if ($path === '/health') {
            return new \React\Http\Message\Response(
                200,
                array_merge($corsHeaders, ['Content-Type' => 'application/json']),
                json_encode(['status' => 'ok'])
            );
        }

        // Route check
        if ($path !== $this->path && $path !== $this->path . '/') {
            return new \React\Http\Message\Response(404, $corsHeaders, 'Not Found');
        }

        // Method check
        if ($method !== 'POST') {
            return new \React\Http\Message\Response(405, $corsHeaders, 'Method Not Allowed');
        }

        $body = (string) $request->getBody();

        if ($body === '') {
            return new \React\Http\Message\Response(
                400,
                array_merge($corsHeaders, ['Content-Type' => 'application/json']),
                JsonRpc::encodeError(null, ErrorCodes::INVALID_REQUEST, 'Empty request body')
            );
        }

        try {
            $message = JsonRpc::parse($body);

            // Build headers array (PSR-7 returns arrays per header, flatten to first value)
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[strtolower($name)] = $values[0] ?? '';
            }

            $queryParams = $request->getQueryParams();
            /** @var array<string, string> $queryParams */
            $authRequest = AuthRequest::fromHttp($headers, $queryParams, $body);

            $result = $server->handle($message, $authRequest);

            return new \React\Http\Message\Response(
                200,
                array_merge($corsHeaders, ['Content-Type' => 'application/json']),
                $result ?? ''
            );
        } catch (JsonRpcException $e) {
            return new \React\Http\Message\Response(
                200, // JSON-RPC errors still return 200
                array_merge($corsHeaders, ['Content-Type' => 'application/json']),
                JsonRpc::encodeError(null, $e->getCode(), $e->getMessage(), $e->data)
            );
        } catch (\Throwable $e) {
            return new \React\Http\Message\Response(
                500,
                array_merge($corsHeaders, ['Content-Type' => 'application/json']),
                JsonRpc::encodeError(null, ErrorCodes::INTERNAL_ERROR, 'Internal error: ' . $e->getMessage())
            );
        }
    }

    public function stop(): void
    {
        if ($this->httpServer !== null) {
            if ($this->httpServer instanceof \React\Socket\SocketServer) {
                $this->httpServer->close();
            } else {
                $this->httpServer->shutdown();
            }
        }
    }
}
