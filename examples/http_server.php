<?php

/**
 * Fastmcphp HTTP Server Example
 *
 * An MCP server running over HTTP using Swoole.
 *
 * Run:
 *   php examples/http_server.php
 *
 * Test:
 *   curl -X POST http://localhost:8080/mcp \
 *     -H "Content-Type: application/json" \
 *     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
 *
 *   curl -X POST http://localhost:8080/mcp \
 *     -H "Content-Type: application/json" \
 *     -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
 *
 *   curl -X POST http://localhost:8080/mcp \
 *     -H "Content-Type: application/json" \
 *     -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"add","arguments":{"a":5,"b":3}}}'
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Fastmcphp\Fastmcphp;

// Create the MCP server
$mcp = new Fastmcphp(
    name: 'Calculator Server',
    version: '1.0.0',
    instructions: 'A calculator server with basic math operations.',
);

// Register calculator tools
$mcp->tool(
    callable: function (int $a, int $b): int {
        return $a + $b;
    },
    name: 'add',
    description: 'Add two numbers',
);

$mcp->tool(
    callable: function (int $a, int $b): int {
        return $a - $b;
    },
    name: 'subtract',
    description: 'Subtract b from a',
);

$mcp->tool(
    callable: function (int $a, int $b): int {
        return $a * $b;
    },
    name: 'multiply',
    description: 'Multiply two numbers',
);

$mcp->tool(
    callable: function (int $a, int $b): float {
        if ($b === 0) {
            throw new InvalidArgumentException('Cannot divide by zero');
        }
        return $a / $b;
    },
    name: 'divide',
    description: 'Divide a by b',
);

// Run with HTTP transport if invoked directly
if (php_sapi_name() === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    $mcp->run(
        transport: 'http',
        host: '0.0.0.0',
        port: 8080,
    );
}

return $mcp;
