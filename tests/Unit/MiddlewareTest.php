<?php

declare(strict_types=1);

namespace Fastmcphp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fastmcphp\Fastmcphp;
use Fastmcphp\Server\Server;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;
use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Middleware\Middleware;
use Fastmcphp\Server\Middleware\MiddlewareContext;
use Fastmcphp\Server\Middleware\MiddlewareInterface;
use Fastmcphp\Protocol\Request;

/**
 * Tests for middleware system.
 */
class MiddlewareTest extends TestCase
{
    private Fastmcphp $mcp;

    protected function setUp(): void
    {
        $this->mcp = new Fastmcphp('Test Server');

        // Register test tools
        $this->mcp->tool(
            callable: fn(string $text) => $text,
            name: 'echo',
        );

        $this->mcp->tool(
            callable: fn(int $a, int $b) => $a + $b,
            name: 'add',
        );
    }

    // =========================================================================
    // Basic Middleware Tests
    // =========================================================================

    public function testMiddlewareOnRequest(): void
    {
        $called = false;
        $capturedMethod = null;

        $middleware = new class($called, $capturedMethod) extends Middleware {
            public function __construct(
                private bool &$called,
                private ?string &$capturedMethod,
            ) {}

            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->called = true;
                $this->capturedMethod = $ctx->method;
                return $next($ctx);
            }
        };

        $this->mcp->addMiddleware($middleware);
        $this->handleRequest('initialize', []);
        $this->handleRequest('tools/list', []);

        $this->assertTrue($called);
        $this->assertEquals('tools/list', $capturedMethod);
    }

    public function testMiddlewareOnCallTool(): void
    {
        $capturedToolName = null;

        $middleware = new class($capturedToolName) extends Middleware {
            public function __construct(private ?string &$capturedToolName) {}

            public function onCallTool(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->capturedToolName = $ctx->getToolName();
                return $next($ctx);
            }
        };

        $this->mcp->addMiddleware($middleware);
        $this->handleRequest('initialize', []);
        $this->handleRequest('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'test']]);

        $this->assertEquals('echo', $capturedToolName);
    }

    public function testMiddlewareOnListTools(): void
    {
        $called = false;

        $middleware = new class($called) extends Middleware {
            public function __construct(private bool &$called) {}

            public function onListTools(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->called = true;
                return $next($ctx);
            }
        };

        $this->mcp->addMiddleware($middleware);
        $this->handleRequest('initialize', []);
        $this->handleRequest('tools/list', []);

        $this->assertTrue($called);
    }

    // =========================================================================
    // Middleware Chain Tests
    // =========================================================================

    public function testMiddlewareChainOrder(): void
    {
        $order = [];

        $middleware1 = new class($order) extends Middleware {
            public function __construct(private array &$order) {}

            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->order[] = 'before1';
                $result = $next($ctx);
                $this->order[] = 'after1';
                return $result;
            }
        };

        $middleware2 = new class($order) extends Middleware {
            public function __construct(private array &$order) {}

            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->order[] = 'before2';
                $result = $next($ctx);
                $this->order[] = 'after2';
                return $result;
            }
        };

        $this->mcp->addMiddleware($middleware1);
        $this->mcp->addMiddleware($middleware2);
        $this->handleRequest('initialize', []);
        $order = []; // Reset after initialize
        $this->handleRequest('tools/list', []);

        // Middleware executes in order added, wrapping each other
        $this->assertEquals(['before1', 'before2', 'after2', 'after1'], $order);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $middleware = new class extends Middleware {
            public function onCallTool(MiddlewareContext $ctx, callable $next): mixed
            {
                // Don't call $next - short circuit
                return [
                    'content' => [['type' => 'text', 'text' => 'blocked']],
                    'isError' => false,
                ];
            }
        };

        $this->mcp->addMiddleware($middleware);
        $this->handleRequest('initialize', []);
        $response = $this->handleRequest('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'original']]);

        $this->assertEquals('blocked', $response['content'][0]['text']);
    }

    public function testMiddlewareCanModifyResult(): void
    {
        $middleware = new class extends Middleware {
            public function onCallTool(MiddlewareContext $ctx, callable $next): mixed
            {
                $result = $next($ctx);

                // Modify the result
                if (isset($result['content'][0]['text'])) {
                    $result['content'][0]['text'] .= ' [modified]';
                }

                return $result;
            }
        };

        $this->mcp->addMiddleware($middleware);
        $this->handleRequest('initialize', []);
        $response = $this->handleRequest('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hello']]);

        $this->assertEquals('hello [modified]', $response['content'][0]['text']);
    }

    // =========================================================================
    // Middleware Context Tests
    // =========================================================================

    public function testMiddlewareContextHasMethod(): void
    {
        $capturedMethod = null;

        $middleware = new class($capturedMethod) extends Middleware {
            public function __construct(private ?string &$capturedMethod) {}

            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->capturedMethod = $ctx->method;
                return $next($ctx);
            }
        };

        $this->mcp->addMiddleware($middleware);
        $this->handleRequest('initialize', []);
        $this->handleRequest('tools/list', []);

        $this->assertEquals('tools/list', $capturedMethod);
    }

    public function testMiddlewareContextHasTimestamp(): void
    {
        $capturedTimestamp = null;

        $middleware = new class($capturedTimestamp) extends Middleware {
            public function __construct(private ?\DateTimeImmutable &$capturedTimestamp) {}

            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->capturedTimestamp = $ctx->timestamp;
                return $next($ctx);
            }
        };

        $this->mcp->addMiddleware($middleware);
        $this->handleRequest('initialize', []);
        $this->handleRequest('tools/list', []);

        $this->assertInstanceOf(\DateTimeImmutable::class, $capturedTimestamp);
    }

    public function testMiddlewareContextAttributes(): void
    {
        $middleware1 = new class extends Middleware {
            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $ctx->setAttribute('custom_key', 'custom_value');
                return $next($ctx);
            }
        };

        $capturedValue = null;
        $middleware2 = new class($capturedValue) extends Middleware {
            public function __construct(private ?string &$capturedValue) {}

            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->capturedValue = $ctx->getAttribute('custom_key');
                return $next($ctx);
            }
        };

        $this->mcp->addMiddleware($middleware1);
        $this->mcp->addMiddleware($middleware2);
        $this->handleRequest('initialize', []);
        $this->handleRequest('tools/list', []);

        $this->assertEquals('custom_value', $capturedValue);
    }

    // =========================================================================
    // Middleware with Authentication Tests
    // =========================================================================

    public function testMiddlewareReceivesUser(): void
    {
        $capturedUserName = null;

        // Setup auth provider
        $authProvider = new class implements AuthProviderInterface {
            public function authenticate(AuthRequest $request): AuthResult
            {
                return AuthResult::success(
                    new AuthenticatedUser(id: '1', name: 'Test User', level: 100),
                    'test-workspace'
                );
            }
        };

        $middleware = new class($capturedUserName) extends Middleware {
            public function __construct(private ?string &$capturedUserName) {}

            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->capturedUserName = $ctx->user?->name;
                return $next($ctx);
            }
        };

        $this->mcp->setAuth($authProvider);
        $this->mcp->addMiddleware($middleware);

        $this->handleRequestWithAuth('initialize', [], 'test-token');
        $this->handleRequestWithAuth('tools/list', [], 'test-token');

        $this->assertEquals('Test User', $capturedUserName);
    }

    public function testMiddlewareReceivesWorkspace(): void
    {
        $capturedWorkspace = null;

        $authProvider = new class implements AuthProviderInterface {
            public function authenticate(AuthRequest $request): AuthResult
            {
                return AuthResult::success(
                    new AuthenticatedUser(id: '1', name: 'Test'),
                    'my-workspace'
                );
            }
        };

        $middleware = new class($capturedWorkspace) extends Middleware {
            public function __construct(private ?string &$capturedWorkspace) {}

            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->capturedWorkspace = $ctx->workspace;
                return $next($ctx);
            }
        };

        $this->mcp->setAuth($authProvider);
        $this->mcp->addMiddleware($middleware);

        $this->handleRequestWithAuth('initialize', [], 'test-token');
        $this->handleRequestWithAuth('tools/list', [], 'test-token');

        $this->assertEquals('my-workspace', $capturedWorkspace);
    }

    // =========================================================================
    // Logging Middleware Example Test
    // =========================================================================

    public function testLoggingMiddleware(): void
    {
        $logs = [];

        $loggingMiddleware = new class($logs) extends Middleware {
            public function __construct(private array &$logs) {}

            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $this->logs[] = "Request: {$ctx->method}";

                $start = microtime(true);
                $result = $next($ctx);
                $elapsed = round((microtime(true) - $start) * 1000, 2);

                $this->logs[] = "Response: {$ctx->method} ({$elapsed}ms)";

                return $result;
            }

            public function onCallTool(MiddlewareContext $ctx, callable $next): mixed
            {
                $toolName = $ctx->getToolName();
                $this->logs[] = "Tool: {$toolName}";

                return $next($ctx);
            }
        };

        $this->mcp->addMiddleware($loggingMiddleware);
        $this->handleRequest('initialize', []);
        $this->handleRequest('tools/list', []);
        $this->handleRequest('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'test']]);

        $this->assertContains('Request: initialize', $logs);
        $this->assertContains('Request: tools/list', $logs);
        $this->assertContains('Request: tools/call', $logs);
        $this->assertContains('Tool: echo', $logs);
    }

    // =========================================================================
    // Rate Limiting Middleware Example Test
    // =========================================================================

    public function testRateLimitingMiddleware(): void
    {
        $requestCounts = [];

        $rateLimitMiddleware = new class($requestCounts) extends Middleware {
            private int $limit = 4;  // Allow initialize + 3 tools/list

            public function __construct(private array &$requestCounts) {}

            public function onRequest(MiddlewareContext $ctx, callable $next): mixed
            {
                $userId = $ctx->user?->id ?? 'anonymous';

                if (!isset($this->requestCounts[$userId])) {
                    $this->requestCounts[$userId] = 0;
                }

                $this->requestCounts[$userId]++;

                if ($this->requestCounts[$userId] > $this->limit) {
                    throw new \RuntimeException('Rate limit exceeded');
                }

                return $next($ctx);
            }
        };

        $this->mcp->addMiddleware($rateLimitMiddleware);

        // Initialize first
        $this->handleRequest('initialize', []);

        // First 3 requests should succeed
        $this->handleRequest('tools/list', []);
        $this->handleRequest('tools/list', []);
        $this->handleRequest('tools/list', []);

        // 4th request should fail (5th total including initialize)
        $this->expectException(\Fastmcphp\Protocol\JsonRpcException::class);
        $this->expectExceptionMessage('Rate limit exceeded');
        $this->handleRequest('tools/list', []);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function handleRequest(string $method, array $params): array
    {
        $request = new Request(
            id: 1,
            method: $method,
            params: $params,
        );

        $response = $this->mcp->getServer()->handle($request);

        if ($response === null) {
            return [];
        }

        $decoded = json_decode($response, true);

        if (isset($decoded['error'])) {
            throw new \Fastmcphp\Protocol\JsonRpcException(
                $decoded['error']['message'],
                $decoded['error']['code']
            );
        }

        return $decoded['result'] ?? [];
    }

    private function handleRequestWithAuth(string $method, array $params, string $token): array
    {
        $request = new Request(
            id: 1,
            method: $method,
            params: $params,
        );

        $authRequest = new AuthRequest(
            headers: ['authorization' => "Bearer {$token}"],
            query: [],
            body: '',
        );

        $response = $this->mcp->getServer()->handle($request, $authRequest);

        if ($response === null) {
            return [];
        }

        $decoded = json_decode($response, true);

        if (isset($decoded['error'])) {
            throw new \Fastmcphp\Protocol\JsonRpcException(
                $decoded['error']['message'],
                $decoded['error']['code']
            );
        }

        return $decoded['result'] ?? [];
    }
}
