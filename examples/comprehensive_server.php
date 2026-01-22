<?php

/**
 * Fastmcphp Comprehensive Server Example
 *
 * Demonstrates all MCP features: tools, resources, resource templates, and prompts.
 *
 * Run with HTTP:
 *   php examples/comprehensive_server.php http
 *
 * Run with SSE:
 *   php examples/comprehensive_server.php sse
 *
 * Run with stdio (default):
 *   php examples/comprehensive_server.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Fastmcphp\Fastmcphp;
use Fastmcphp\Server\Context;
use Fastmcphp\Prompts\Message;
use Fastmcphp\Content\TextContent;
use Fastmcphp\Tools\ToolResult;

// Create the MCP server
$mcp = new Fastmcphp(
    name: 'Comprehensive Demo Server',
    version: '1.0.0',
    instructions: 'A demonstration server showing all MCP capabilities in PHP.',
);

// ============================================================================
// TOOLS
// ============================================================================

// Simple string tool
$mcp->tool(
    callable: function (string $name): string {
        return "Hello, {$name}!";
    },
    name: 'greet',
    description: 'Greet someone by name',
);

// Tool with multiple parameters and types
$mcp->tool(
    callable: function (float $celsius): array {
        $fahrenheit = ($celsius * 9 / 5) + 32;
        $kelvin = $celsius + 273.15;
        return [
            'celsius' => $celsius,
            'fahrenheit' => round($fahrenheit, 2),
            'kelvin' => round($kelvin, 2),
        ];
    },
    name: 'convert_temperature',
    description: 'Convert temperature from Celsius to Fahrenheit and Kelvin',
);

// Tool with optional parameters
$mcp->tool(
    callable: function (string $text, bool $uppercase = false, bool $reverse = false): string {
        $result = $text;
        if ($uppercase) {
            $result = strtoupper($result);
        }
        if ($reverse) {
            $result = strrev($result);
        }
        return $result;
    },
    name: 'transform_text',
    description: 'Transform text with optional uppercase and reverse',
);

// Tool with context injection (for logging)
$mcp->tool(
    callable: function (string $query, Context $ctx): array {
        $ctx->info("Processing search query: {$query}");

        // Simulate search results
        $results = [
            ['title' => "Result 1 for '{$query}'", 'score' => 0.95],
            ['title' => "Result 2 for '{$query}'", 'score' => 0.87],
            ['title' => "Result 3 for '{$query}'", 'score' => 0.72],
        ];

        $ctx->debug('Found ' . count($results) . ' results');

        return $results;
    },
    name: 'search',
    description: 'Search for information (demonstrates context injection)',
);

// Tool returning custom ToolResult
$mcp->tool(
    callable: function (string $markdown): ToolResult {
        // Parse markdown (simplified)
        $html = str_replace(
            ['**', '__', '`'],
            ['<strong>', '<em>', '<code>'],
            $markdown
        );

        return new ToolResult(
            content: [
                new TextContent("Parsed markdown:\n{$html}"),
            ],
            structuredContent: [
                'original' => $markdown,
                'html' => $html,
                'length' => strlen($markdown),
            ],
        );
    },
    name: 'parse_markdown',
    description: 'Parse markdown text (demonstrates custom ToolResult)',
);

// ============================================================================
// RESOURCES
// ============================================================================

// Static resource
$mcp->resource(
    uri: 'config://app',
    callable: function (): array {
        return [
            'name' => 'Fastmcphp Demo',
            'version' => '1.0.0',
            'environment' => 'development',
            'features' => ['tools', 'resources', 'prompts'],
        ];
    },
    name: 'app_config',
    description: 'Application configuration',
    mimeType: 'application/json',
);

// Static text resource
$mcp->resource(
    uri: 'docs://readme',
    callable: function (): string {
        return <<<'README'
# Fastmcphp

A PHP implementation of the Model Context Protocol (MCP) using Swoole.

## Features

- Tools: Define callable functions as MCP tools
- Resources: Expose data via URI-based resources
- Resource Templates: Dynamic resources with parameters
- Prompts: Define reusable prompt templates
- Multiple transports: stdio, HTTP, SSE

## Quick Start

```php
$mcp = new Fastmcphp('My Server');
$mcp->tool(fn(string $text) => $text, name: 'echo');
$mcp->run();
```
README;
    },
    name: 'readme',
    description: 'Project README',
    mimeType: 'text/markdown',
);

// ============================================================================
// RESOURCE TEMPLATES (parameterized resources)
// ============================================================================

// User profile template
$mcp->resource(
    uri: 'users://{id}',
    callable: function (int $id): array {
        // Simulated user data
        $users = [
            1 => ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
            2 => ['id' => 2, 'name' => 'Bob', 'role' => 'user'],
            3 => ['id' => 3, 'name' => 'Charlie', 'role' => 'user'],
        ];

        return $users[$id] ?? ['error' => 'User not found'];
    },
    name: 'user_profile',
    description: 'Get user profile by ID',
    mimeType: 'application/json',
);

// Weather template
$mcp->resource(
    uri: 'weather://{city}',
    callable: function (string $city): array {
        // Simulated weather data
        return [
            'city' => ucfirst($city),
            'temperature' => rand(10, 30),
            'conditions' => ['sunny', 'cloudy', 'rainy'][rand(0, 2)],
            'humidity' => rand(30, 90) . '%',
        ];
    },
    name: 'weather',
    description: 'Get weather for a city',
    mimeType: 'application/json',
);

// ============================================================================
// PROMPTS
// ============================================================================

// Simple prompt
$mcp->prompt(
    callable: function (string $topic): array {
        return [
            Message::user("I'd like to learn about {$topic}. Can you explain it in simple terms?"),
        ];
    },
    name: 'explain',
    description: 'Generate a prompt to explain a topic',
);

// Multi-turn prompt
$mcp->prompt(
    callable: function (string $code, string $language = 'php'): array {
        return [
            Message::user("Please review the following {$language} code:\n\n```{$language}\n{$code}\n```"),
            Message::assistant("I'll review this code for you. Let me analyze it for potential issues, best practices, and suggestions for improvement."),
            Message::user("Focus on security concerns and performance optimizations."),
        ];
    },
    name: 'code_review',
    description: 'Generate a code review prompt',
);

// Prompt with structured output request
$mcp->prompt(
    callable: function (string $text): array {
        return [
            Message::user(<<<PROMPT
Analyze the following text and provide a structured response:

Text: {$text}

Please respond with:
1. Main topic
2. Key points (bullet list)
3. Sentiment (positive/negative/neutral)
4. Summary (1-2 sentences)
PROMPT),
        ];
    },
    name: 'analyze_text',
    description: 'Generate a text analysis prompt',
);

// ============================================================================
// RUN SERVER
// ============================================================================

// Determine transport from command line
$transport = $argv[1] ?? 'stdio';

echo "Starting Comprehensive Demo Server with {$transport} transport...\n";

$mcp->run(
    transport: $transport,
    host: '0.0.0.0',
    port: 8080,
);
