# Fastmcphp

## NOTE:
- sse is deprecated according to anthropic: https://code.claude.com/docs/en/mcp#option-2%3A-add-a-remote-sse-server
- use http instead

A PHP 8.2+ implementation of the [Model Context Protocol (MCP)](https://modelcontextprotocol.io/).

Inspired by [FastMCP](https://github.com/jlowin/fastmcp) for Python.

## Requirements

- PHP 8.2+
- Swoole 5.0+ or OpenSwoole 4.0+ (optional, recommended for production HTTP/SSE)

## Installation

```bash
composer require fastmcphp/fastmcphp
```

## Quick Start

```php
<?php

use Fastmcphp\Fastmcphp;

$mcp = new Fastmcphp('My Server');

// Register a tool
$mcp->tool(
    callable: fn(string $text) => $text,
    name: 'echo',
    description: 'Echo the input text',
);

// Register a resource
$mcp->resource(
    uri: 'config://app',
    callable: fn() => ['version' => '1.0.0'],
    name: 'config',
);

// Run with stdio transport
$mcp->run();
```

## Features

### Tools

Define callable functions that can be invoked by MCP clients:

```php
// Simple tool
$mcp->tool(
    callable: fn(int $a, int $b) => $a + $b,
    name: 'add',
    description: 'Add two numbers',
);

// Tool with context injection
$mcp->tool(
    callable: function(string $query, Context $ctx): array {
        $ctx->info("Searching for: {$query}");
        return ['results' => []];
    },
    name: 'search',
);
```

### Resources

Expose data via URI-based resources:

```php
// Static resource
$mcp->resource(
    uri: 'config://database',
    callable: fn() => ['host' => 'localhost', 'port' => 5432],
);

// Parameterized resource (template)
$mcp->resource(
    uri: 'users://{id}',
    callable: fn(int $id) => getUserById($id),
);
```

### Prompts

Define reusable prompt templates:

```php
use Fastmcphp\Prompts\Message;

$mcp->prompt(
    callable: fn(string $topic) => [
        Message::user("Explain {$topic} in simple terms"),
    ],
    name: 'explain',
);
```

### Transports

Three transport modes are supported:

```php
// Stdio (default) - for subprocess communication
$mcp->run(transport: 'stdio');

// HTTP - JSON-RPC over HTTP (falls back to built-in PHP server without Swoole)
$mcp->run(transport: 'http', host: '0.0.0.0', port: 8080);

// SSE - Server-Sent Events (deprecated, use HTTP instead)
$mcp->run(transport: 'sse', host: '0.0.0.0', port: 8080);
```

The HTTP transport automatically detects whether Swoole/OpenSwoole is installed. With Swoole, it runs a high-performance async server with multiple workers. Without Swoole, it uses ReactPHP's event-loop-based HTTP server (async, single-process). Swoole is recommended for production deployments.

### Authentication

Implement custom authentication providers for bearer tokens, API keys, or any auth system:

```php
use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;

class MyAuthProvider implements AuthProviderInterface
{
    public function authenticate(AuthRequest $request): AuthResult
    {
        $token = $request->getBearerToken();

        if (!$token) {
            return AuthResult::unauthenticated();
        }

        // Validate token against your auth system
        $user = $this->validateToken($token);

        if (!$user) {
            return AuthResult::failed('Invalid token');
        }

        return AuthResult::success(new AuthenticatedUser(
            id: $user['id'],
            name: $user['name'],
            level: $user['level'],
            scopes: $user['scopes'],
            workspace: $user['workspace'],
        ));
    }
}

// Use the auth provider
$mcp->setAuth(new MyAuthProvider(), required: true);
```

### AuthRequest

`AuthRequest` is the abstraction layer between transports and authentication. It normalizes request data from any transport (Swoole HTTP, built-in PHP HTTP, SSE) so auth providers don't need to know transport details.

**Token extraction** — use `getToken()` for automatic priority-based lookup:

1. `X-API-TOKEN` header (via `getApiToken()`)
2. `Authorization: Bearer <token>` header (via `getBearerToken()`)
3. `?key=<token>` query parameter (via `getApiKeyFromQuery()`)

**Available methods:**

| Method | Description |
|--------|-------------|
| `getToken()` | Get token from any source (priority order above) |
| `getHeader(string $name)` | Get any header value (case-insensitive) |
| `getBearerToken()` | Extract token from `Authorization: Bearer` header |
| `getApiToken()` | Get `X-API-TOKEN` header value |
| `getApiKeyFromQuery(string $param = 'key')` | Get token from query parameter |
| `getQuery(string $key)` | Get any query parameter |

**Sending tokens from clients:**

```bash
# Using Authorization header (recommended)
curl -X POST http://localhost:8080/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your-token-here" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'

# Using X-API-TOKEN header
curl -X POST http://localhost:8080/mcp \
  -H "Content-Type: application/json" \
  -H "X-API-TOKEN: your-token-here" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'

# Using query parameter
curl -X POST "http://localhost:8080/mcp?key=your-token-here" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

### Per-Tool Authorization

Control access to individual tools, resources, or prompts:

```php
use Fastmcphp\Server\Auth\AuthorizationContext;

// Admin-only tool
$mcp->tool(
    callable: fn() => 'sensitive data',
    name: 'admin_tool',
    auth: fn(AuthorizationContext $ctx) => $ctx->user->hasLevel(50), // ADMIN level
);

// Scope-based authorization
$mcp->tool(
    callable: fn(string $query) => search($query),
    name: 'search',
    auth: fn(AuthorizationContext $ctx) => $ctx->user->hasScope('tools:search'),
);
```

### Middleware

Intercept and modify requests/responses:

```php
use Fastmcphp\Server\Middleware\Middleware;
use Fastmcphp\Server\Middleware\MiddlewareContext;

class LoggingMiddleware extends Middleware
{
    public function onCallTool(MiddlewareContext $ctx, callable $next): mixed
    {
        $toolName = $ctx->getToolName();
        $user = $ctx->user?->name ?? 'anonymous';

        echo "[{$user}] Calling tool: {$toolName}\n";

        $start = microtime(true);
        $result = $next($ctx);
        $elapsed = microtime(true) - $start;

        echo "[{$user}] Tool completed in {$elapsed}s\n";

        return $result;
    }
}

$mcp->addMiddleware(new LoggingMiddleware());
```

## Multi-Tenant Workspace Support

Fastmcphp supports multi-tenant architectures through the authentication system:

```php
// Auth provider returns workspace context
return AuthResult::success($user, workspace: 'tenant-123');

// Workspace is available in middleware and authorization
$mcp->tool(
    callable: fn() => getWorkspaceData(),
    name: 'get_data',
    auth: fn(AuthorizationContext $ctx) => $ctx->workspace === 'allowed-tenant',
);
```

## Testing

```bash
# Test stdio transport
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | php examples/echo_server.php

# Test tools/list
echo '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' | php examples/echo_server.php

# Test tools/call
echo '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"text":"Hello!"}}}' | php examples/echo_server.php

# Test authenticated HTTP server
php examples/authenticated_server.php &
curl -X POST http://localhost:8080/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer user-token-123" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

## MCP Protocol Support

| Feature | Status |
|---------|--------|
| Tools | ✅ |
| Resources | ✅ |
| Resource Templates | ✅ |
| Prompts | ✅ |
| Stdio Transport | ✅ |
| HTTP Transport | ✅ |
| SSE Transport | ✅ |
| Authentication | ✅ |
| Middleware | ✅ |
| Per-Component Auth | ✅ |
| Scopes | ✅ |
| Multi-Tenant | ✅ |
| Pagination | ❌ |

## License

MIT
