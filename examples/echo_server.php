<?php

/**
 * Fastmcphp Echo Server Example
 *
 * A simple MCP server that demonstrates tools, resources, and prompts.
 *
 * Run with stdio:
 *   php examples/echo_server.php
 *
 * Test with:
 *   echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | php examples/echo_server.php
 *   echo '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' | php examples/echo_server.php
 *   echo '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"text":"Hello!"}}}' | php examples/echo_server.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Fastmcphp\Fastmcphp;
use Fastmcphp\Prompts\Message;

// Create the MCP server
$mcp = new Fastmcphp(
    name: 'Echo Server',
    version: '1.0.0',
    instructions: 'A simple echo server that demonstrates MCP capabilities.',
);

// Register a simple echo tool
$mcp->tool(
    callable: function (string $text): string {
        return $text;
    },
    name: 'echo',
    description: 'Echo the input text back',
);

// Register a reverse tool
$mcp->tool(
    callable: function (string $text): string {
        return strrev($text);
    },
    name: 'reverse',
    description: 'Reverse the input text',
);

// Register a static resource
$mcp->resource(
    uri: 'echo://static',
    callable: function (): string {
        return 'This is a static echo resource!';
    },
    name: 'static_echo',
    description: 'A static echo message',
);

// Register a parameterized resource (template)
$mcp->resource(
    uri: 'echo://{message}',
    callable: function (string $message): string {
        return "Echo: {$message}";
    },
    name: 'echo_template',
    description: 'Echo the message from the URI',
);

// Register a simple prompt
$mcp->prompt(
    callable: function (string $topic): array {
        return [
            Message::user("Please analyze the following topic: {$topic}"),
            Message::assistant("I'll analyze that topic for you."),
        ];
    },
    name: 'analyze',
    description: 'Generate an analysis prompt for a topic',
);

// Run with stdio transport (default) if invoked directly
if (php_sapi_name() === 'cli' && realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    $mcp->run(transport: 'stdio');
}

// Return server for CLI tools
return $mcp;
