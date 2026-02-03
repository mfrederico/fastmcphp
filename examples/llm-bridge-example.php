<?php
/**
 * Example: Using the LLM Bridge to connect Ollama to MCP servers
 *
 * This demonstrates how to:
 * 1. Load MCP servers from a .mcp.json config
 * 2. Chat with Ollama and have it call MCP tools
 * 3. Stream responses with tool execution
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Fastmcphp\Llm\LlmBridge;
use Fastmcphp\Llm\OllamaProvider;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Set up logging
$logger = new Logger('llm-bridge');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Create Ollama provider
$ollama = new OllamaProvider([
    'host' => 'http://localhost:11434',
    'model' => 'qwen3-coder:30b',
    'temperature' => 0.7,
    'num_ctx' => 32768,
], $logger);

// Check if Ollama is available
if (!$ollama->isAvailable()) {
    die("Ollama is not available at localhost:11434\n");
}

// Create the LLM Bridge
$bridge = new LlmBridge($ollama, $logger);

// Set workspace for multi-tenant routing
$bridge->setWorkspace('gwt');

// Option 1: Load from .mcp.json file
// $bridge->loadFromConfig('/path/to/.mcp.json');

// Option 2: Connect to MCP servers manually
// HTTP transport (remote MyCTOBot server)
$bridge->connectMcpHttp(
    'myctobot',
    'https://gwt.myctobot.ai/mcp/pipelines',
    ['Authorization: Bearer YOUR_TOKEN_HERE']
);

// stdio transport (local MCP server)
// $bridge->connectMcpStdio(
//     'filesystem',
//     'npx -y @anthropic/mcp-server-filesystem /tmp'
// );

// Show available tools
echo "Available tools:\n";
foreach ($bridge->getAvailableTools() as $tool) {
    echo "  - {$tool['function']['name']}: {$tool['function']['description']}\n";
}
echo "\n";

// Chat with streaming and tool execution
$response = $bridge->chat(
    "What pipelines are available? Run the 'email-release-control' pipeline if it exists.",
    [
        'system' => 'You are a helpful assistant with access to MyCTOBot automation tools. Use the available tools to help the user.',
        'stream' => true,
        'onChunk' => function ($chunk) {
            echo $chunk;
            flush();
        },
        'onToolCall' => function ($toolCall) {
            echo "\n[Calling tool: {$toolCall['name']}]\n";
        },
        'onToolResult' => function ($toolCall, $result) {
            $status = $result['success'] ? 'success' : 'failed';
            echo "[Tool result: {$status}]\n";
        },
    ]
);

echo "\n\n";
echo "---\n";
echo "Completed in {$response->iterations} iteration(s)\n";
echo "Tools called: " . count($response->toolCalls) . "\n";

if (!$response->success) {
    echo "Error: {$response->error}\n";
}
