<?php

/**
 * Test HTTP server for HttpTransportTest.
 *
 * Usage: php http_test_server.php <port> [auth_mode]
 *   auth_mode: "none" (default) or "bearer"
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Fastmcphp\Fastmcphp;
use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;

$port = (int) ($argv[1] ?? 8080);
$authMode = $argv[2] ?? 'none';

$mcp = new Fastmcphp('Test HTTP Server', '1.0.0');

// Register a simple echo tool
$mcp->tool(
    callable: fn(string $text) => $text,
    name: 'echo',
    description: 'Echo the input text',
);

// Register a simple resource
$mcp->resource(
    uri: 'config://test',
    callable: fn() => ['version' => '1.0.0'],
    name: 'test_config',
);

// Set up auth if requested
if ($authMode === 'bearer') {
    $mcp->setAuth(new class implements AuthProviderInterface {
        public function authenticate(AuthRequest $request): AuthResult
        {
            $token = $request->getToken();

            if ($token === null) {
                return AuthResult::unauthenticated();
            }

            if ($token === 'valid-token') {
                return AuthResult::success(
                    new AuthenticatedUser(id: '1', name: 'Test User', level: 100)
                );
            }

            return AuthResult::failed('Invalid token');
        }
    }, required: true);
}

$mcp->run(transport: 'http', host: '127.0.0.1', port: $port);
