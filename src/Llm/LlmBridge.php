<?php

declare(strict_types=1);

namespace Fastmcphp\Llm;

use Fastmcphp\Client\McpClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * LLM Bridge - Connects LLM providers to MCP servers.
 *
 * This enables LLMs (like Ollama) to call tools exposed by MCP servers.
 *
 * Usage:
 *   $bridge = new LlmBridge(new OllamaProvider(['model' => 'qwen3-coder:30b']));
 *   $bridge->connectMcp('pipelines', 'http://localhost:8080/mcp/pipelines');
 *   $bridge->connectMcp('shopify', 'http://localhost:8080/mcp/shopify');
 *
 *   $response = $bridge->chat("Run the flash sale pipeline", [
 *       'system' => 'You are a helpful assistant with access to automation tools.',
 *       'stream' => true,
 *       'onChunk' => fn($chunk) => echo $chunk,
 *   ]);
 */
class LlmBridge
{
    /** @var McpClient[] */
    private array $mcpClients = [];

    /** @var array Tool name -> MCP client name mapping */
    private array $toolRouting = [];

    /** @var string|null Current workspace/tenant */
    private ?string $workspace = null;

    public function __construct(
        private readonly LlmProviderInterface $llmProvider,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxToolIterations = 10,
    ) {}

    /**
     * Set the workspace/tenant context
     * This is passed to MCP servers for multi-tenant routing
     */
    public function setWorkspace(?string $workspace): self
    {
        $this->workspace = $workspace;
        return $this;
    }

    /**
     * Get current workspace
     */
    public function getWorkspace(): ?string
    {
        return $this->workspace;
    }

    /**
     * Load MCP servers from a .mcp.json config file
     *
     * Supports the same format as Claude Code:
     * {
     *   "mcpServers": {
     *     "pipelines": {
     *       "type": "http",
     *       "url": "https://gwt.myctobot.ai/mcp/pipelines",
     *       "headers": { "Authorization": "Bearer xxx" }
     *     },
     *     "filesystem": {
     *       "type": "stdio",
     *       "command": "npx",
     *       "args": ["-y", "@anthropic/mcp-server-filesystem", "/path"]
     *     }
     *   }
     * }
     *
     * @param string $configPath Path to .mcp.json file
     * @return array Results for each server ['name' => bool success]
     */
    public function loadFromConfig(string $configPath): array
    {
        if (!file_exists($configPath)) {
            $this->logger->error("MCP config file not found", ['path' => $configPath]);
            return [];
        }

        $content = file_get_contents($configPath);
        $config = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("Invalid JSON in MCP config", ['path' => $configPath]);
            return [];
        }

        $servers = $config['mcpServers'] ?? [];
        $results = [];

        foreach ($servers as $name => $serverConfig) {
            $type = $serverConfig['type'] ?? 'stdio';

            if ($type === 'http') {
                $url = $serverConfig['url'] ?? '';
                $headers = [];

                // Convert headers object to array format
                foreach ($serverConfig['headers'] ?? [] as $key => $value) {
                    // Support variable substitution for workspace
                    if ($this->workspace) {
                        $value = str_replace('{workspace}', $this->workspace, $value);
                    }
                    $headers[] = "{$key}: {$value}";
                }

                $results[$name] = $this->connectMcpHttp($name, $url, $headers);

            } elseif ($type === 'stdio') {
                $command = $serverConfig['command'] ?? '';
                $args = $serverConfig['args'] ?? [];

                // Build full command with args
                $fullCommand = $command;
                foreach ($args as $arg) {
                    $fullCommand .= ' ' . escapeshellarg($arg);
                }

                $workingDir = $serverConfig['cwd'] ?? null;
                $env = $serverConfig['env'] ?? [];

                $results[$name] = $this->connectMcpStdio($name, $fullCommand, $workingDir, $env);

            } else {
                $this->logger->warning("Unknown MCP server type", ['name' => $name, 'type' => $type]);
                $results[$name] = false;
            }
        }

        return $results;
    }

    /**
     * Generate a .mcp.json config for MyCTOBot servers
     *
     * @param string $baseUrl Base URL (e.g., https://gwt.myctobot.ai)
     * @param string $token Bearer token for authentication
     * @param array $servers List of server names to include
     * @return array Config array (json_encode for file)
     */
    public static function generateMyctoConfig(string $baseUrl, string $token, array $servers = ['pipelines', 'shopify']): array
    {
        $config = ['mcpServers' => []];

        foreach ($servers as $server) {
            $config['mcpServers'][$server] = [
                'type' => 'http',
                'url' => rtrim($baseUrl, '/') . '/mcp/' . $server,
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                ],
            ];
        }

        return $config;
    }

    /**
     * Connect to an MCP server via HTTP
     *
     * @param string $name Unique name for this MCP connection
     * @param string $endpoint HTTP endpoint of the MCP server
     * @param array $headers Optional headers (e.g., Authorization)
     */
    public function connectMcpHttp(string $name, string $endpoint, array $headers = []): bool
    {
        // Add workspace header if set
        if ($this->workspace) {
            $headers[] = "X-Workspace: {$this->workspace}";
        }

        $client = McpClient::http($name, $endpoint, $headers, $this->logger);
        return $this->registerMcpClient($client);
    }

    /**
     * Connect to an MCP server via stdio (subprocess)
     *
     * @param string $name Unique name for this MCP connection
     * @param string $command Command to start the MCP server
     * @param string|null $workingDir Working directory
     * @param array $env Environment variables
     */
    public function connectMcpStdio(string $name, string $command, ?string $workingDir = null, array $env = []): bool
    {
        // Add workspace to environment if set
        if ($this->workspace) {
            $env['MCP_WORKSPACE'] = $this->workspace;
        }

        $client = McpClient::stdio($name, $command, $workingDir, $env, $this->logger);
        return $this->registerMcpClient($client);
    }

    /**
     * Register an MCP client and connect
     */
    private function registerMcpClient(McpClient $client): bool
    {
        if (!$client->connect()) {
            $this->logger->error("Failed to connect to MCP server", ['name' => $client->getName()]);
            return false;
        }

        $name = $client->getName();
        $this->mcpClients[$name] = $client;

        // Map tools to this client
        foreach ($client->getTools() as $tool) {
            $fullName = $name . '__' . $tool['name'];
            $this->toolRouting[$fullName] = $name;
        }

        $this->logger->info("Connected to MCP server", [
            'name' => $name,
            'tools' => count($client->getTools()),
        ]);

        return true;
    }

    /**
     * Add an already-connected MCP client
     */
    public function addMcpClient(McpClient $client): void
    {
        $name = $client->getName();
        $this->mcpClients[$name] = $client;

        foreach ($client->getTools() as $tool) {
            $fullName = $name . '__' . $tool['name'];
            $this->toolRouting[$fullName] = $name;
        }
    }

    /**
     * Get all available tools from connected MCP servers
     */
    public function getAvailableTools(): array
    {
        $tools = [];
        foreach ($this->mcpClients as $client) {
            $tools = array_merge($tools, $client->getToolsForLlm());
        }
        return $tools;
    }

    /**
     * Chat with the LLM, automatically routing tool calls to MCP servers
     *
     * @param string $message User message
     * @param array $options Options:
     *   - system: System prompt
     *   - history: Previous conversation messages
     *   - stream: Whether to stream response (default: false)
     *   - onChunk: Callback for streamed chunks
     *   - onToolCall: Callback when tool is called
     *   - onToolResult: Callback when tool returns
     * @return LlmBridgeResponse
     */
    public function chat(string $message, array $options = []): LlmBridgeResponse
    {
        $messages = $options['history'] ?? [];

        // Add system prompt if provided
        if (!empty($options['system'])) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => $options['system'],
            ]);
        }

        // Add user message
        $messages[] = ['role' => 'user', 'content' => $message];

        // Get available tools
        $tools = $this->getAvailableTools();

        $stream = $options['stream'] ?? false;
        $onChunk = $options['onChunk'] ?? null;
        $onToolCall = $options['onToolCall'] ?? null;
        $onToolResult = $options['onToolResult'] ?? null;

        $fullContent = '';
        $allToolCalls = [];
        $iteration = 0;

        // Tool calling loop
        while ($iteration < $this->maxToolIterations) {
            $iteration++;

            if ($stream && $onChunk) {
                $response = $this->llmProvider->chatStream(
                    $messages,
                    $tools,
                    function ($chunk) use (&$fullContent, $onChunk) {
                        $fullContent .= $chunk;
                        $onChunk($chunk);
                    },
                    $onToolCall
                );
            } else {
                $response = $this->llmProvider->chat($messages, $tools);
                $fullContent .= $response->content;
            }

            if (!$response->success) {
                return new LlmBridgeResponse(
                    content: $fullContent,
                    success: false,
                    error: $response->error,
                );
            }

            // No tool calls - we're done
            if (!$response->hasToolCalls()) {
                break;
            }

            // Process tool calls
            $toolResults = [];
            foreach ($response->getToolCalls() as $toolCall) {
                $allToolCalls[] = $toolCall;

                $result = $this->executeToolCall($toolCall);

                if ($onToolResult) {
                    $onToolResult($toolCall, $result);
                }

                $toolResults[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                    'content' => $result['text'] ?? json_encode($result),
                ];
            }

            // Add assistant message with tool calls
            $messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls' => array_map(fn($tc) => [
                    'id' => $tc['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $tc['name'],
                        // Ensure empty args encode as {} not []
                        'arguments' => json_encode(
                            empty($tc['arguments']) ? (object)[] : $tc['arguments']
                        ),
                    ],
                ], $response->getToolCalls()),
            ];

            // Add tool results
            foreach ($toolResults as $result) {
                $messages[] = $result;
            }
        }

        return new LlmBridgeResponse(
            content: $fullContent,
            success: true,
            toolCalls: $allToolCalls,
            iterations: $iteration,
        );
    }

    /**
     * Execute a tool call by routing to the appropriate MCP server
     */
    private function executeToolCall(array $toolCall): array
    {
        $fullName = $toolCall['name'];

        // Check if this tool is routed to an MCP server
        if (!isset($this->toolRouting[$fullName])) {
            $this->logger->warning("Unknown tool", ['name' => $fullName]);
            return [
                'success' => false,
                'error' => "Unknown tool: {$fullName}",
                'text' => "Error: Unknown tool '{$fullName}'",
            ];
        }

        $mcpName = $this->toolRouting[$fullName];
        $client = $this->mcpClients[$mcpName] ?? null;

        if (!$client) {
            return [
                'success' => false,
                'error' => "MCP client not found: {$mcpName}",
                'text' => "Error: MCP server '{$mcpName}' not connected",
            ];
        }

        // Extract the actual tool name (without MCP server prefix)
        $toolName = substr($fullName, strlen($mcpName) + 2); // +2 for '__'

        $this->logger->info("Executing tool call", [
            'mcp' => $mcpName,
            'tool' => $toolName,
            'arguments' => $toolCall['arguments'],
        ]);

        return $client->callTool($toolName, $toolCall['arguments']);
    }

    /**
     * Get connected MCP client names
     */
    public function getConnectedMcpServers(): array
    {
        return array_keys($this->mcpClients);
    }
}
