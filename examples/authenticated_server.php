<?php

/**
 * Fastmcphp Authenticated Server Example
 *
 * Demonstrates authentication and authorization:
 * - Bearer token authentication
 * - Scope-based authorization
 * - Per-tool authorization callbacks
 * - Logging middleware
 *
 * Test with:
 *   # Start HTTP server
 *   php examples/authenticated_server.php
 *
 *   # Test without auth (should fail)
 *   curl -X POST http://localhost:8080/mcp \
 *     -H "Content-Type: application/json" \
 *     -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
 *
 *   # Test with valid token
 *   curl -X POST http://localhost:8080/mcp \
 *     -H "Content-Type: application/json" \
 *     -H "Authorization: Bearer user-token-123" \
 *     -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
 *
 *   # Test calling a tool
 *   curl -X POST http://localhost:8080/mcp \
 *     -H "Content-Type: application/json" \
 *     -H "Authorization: Bearer user-token-123" \
 *     -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"text":"Hello!"}}}'
 *
 *   # Test admin-only tool with user token (should fail)
 *   curl -X POST http://localhost:8080/mcp \
 *     -H "Content-Type: application/json" \
 *     -H "Authorization: Bearer user-token-123" \
 *     -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"admin_tool","arguments":{}}}'
 *
 *   # Test admin-only tool with admin token (should work)
 *   curl -X POST http://localhost:8080/mcp \
 *     -H "Content-Type: application/json" \
 *     -H "Authorization: Bearer admin-token-456" \
 *     -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"admin_tool","arguments":{}}}'
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/auth/BearerTokenAuthProvider.php';

use Fastmcphp\Fastmcphp;
use Fastmcphp\Server\Auth\AuthorizationContext;
use Fastmcphp\Server\Middleware\Middleware;
use Fastmcphp\Server\Middleware\MiddlewareContext;
use Fastmcphp\Examples\Auth\BearerTokenAuthProvider;

// Define permission levels (matching myctobot pattern)
const LEVEL_ROOT = 1;
const LEVEL_ADMIN = 50;
const LEVEL_MEMBER = 100;
const LEVEL_PUBLIC = 101;

// Create a simple logging middleware
class LoggingMiddleware extends Middleware
{
    public function onRequest(MiddlewareContext $context, callable $next): mixed
    {
        $user = $context->user?->name ?? 'anonymous';
        $workspace = $context->workspace ?? 'default';
        echo "[{$workspace}] {$user} -> {$context->method}\n";

        $start = microtime(true);
        $result = $next($context);
        $elapsed = round((microtime(true) - $start) * 1000, 2);

        echo "[{$workspace}] {$user} <- {$context->method} ({$elapsed}ms)\n";

        return $result;
    }

    public function onCallTool(MiddlewareContext $context, callable $next): mixed
    {
        $toolName = $context->getToolName();
        echo "  Tool: {$toolName}\n";
        return $next($context);
    }
}

// Create the MCP server
$mcp = new Fastmcphp(
    name: 'Authenticated Server',
    version: '1.0.0',
    instructions: 'An MCP server with authentication and authorization.',
);

// Configure authentication with test tokens
$mcp->setAuth(BearerTokenAuthProvider::withTokens([
    'user-token-123' => [
        'member' => [
            'id' => 1,
            'username' => 'alice',
            'display_name' => 'Alice',
            'email' => 'alice@example.com',
            'level' => LEVEL_MEMBER,
        ],
        'apikey' => [
            'id' => 1,
            'name' => 'Alice API Key',
            'scopes_json' => json_encode(['tools:*', 'resources:*']),
        ],
        'workspace' => 'demo',
    ],
    'admin-token-456' => [
        'member' => [
            'id' => 2,
            'username' => 'bob',
            'display_name' => 'Bob (Admin)',
            'email' => 'bob@example.com',
            'level' => LEVEL_ADMIN,
        ],
        'apikey' => [
            'id' => 2,
            'name' => 'Bob Admin Key',
            'scopes_json' => json_encode(['*:*']), // Full access
        ],
        'workspace' => 'demo',
    ],
    'limited-token-789' => [
        'member' => [
            'id' => 3,
            'username' => 'charlie',
            'display_name' => 'Charlie (Limited)',
            'email' => 'charlie@example.com',
            'level' => LEVEL_MEMBER,
        ],
        'apikey' => [
            'id' => 3,
            'name' => 'Charlie Limited Key',
            'scopes_json' => json_encode(['tools:echo']), // Only echo tool
        ],
        'workspace' => 'demo',
    ],
]), required: true);

// Add logging middleware
$mcp->addMiddleware(new LoggingMiddleware());

// Public tool - anyone authenticated can use
$mcp->tool(
    callable: fn(string $text) => $text,
    name: 'echo',
    description: 'Echo the input text back',
);

// Tool with per-tool authorization - admin only
$mcp->tool(
    callable: fn() => 'This is sensitive admin data',
    name: 'admin_tool',
    description: 'Admin-only tool',
    auth: fn(AuthorizationContext $ctx) => $ctx->user->hasLevel(LEVEL_ADMIN),
);

// Tool that uses workspace context
$mcp->tool(
    callable: function (string $query) use ($mcp): array {
        // In a real app, you'd query workspace-specific data
        return [
            'query' => $query,
            'workspace' => 'demo', // Would come from context
            'results' => ['item1', 'item2'],
        ];
    },
    name: 'search',
    description: 'Search within the current workspace',
);

// Resource with authorization
$mcp->resource(
    uri: 'config://workspace',
    callable: fn() => ['name' => 'Demo Workspace', 'features' => ['mcp', 'auth']],
    name: 'workspace_config',
    description: 'Current workspace configuration',
    auth: fn(AuthorizationContext $ctx) => $ctx->user->hasLevel(LEVEL_MEMBER),
);

echo "Starting authenticated server on http://localhost:8080\n";
echo "Test tokens:\n";
echo "  - user-token-123 (Alice, member level)\n";
echo "  - admin-token-456 (Bob, admin level)\n";
echo "  - limited-token-789 (Charlie, echo only)\n";
echo "\n";

$mcp->run(transport: 'http', port: 8080);
