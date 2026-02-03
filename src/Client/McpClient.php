<?php

declare(strict_types=1);

namespace Fastmcphp\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MCP Client - Connect to MCP servers and call their tools.
 *
 * Supports:
 *   - stdio transport (local subprocess)
 *   - HTTP transport (remote servers via Streamable HTTP)
 */
class McpClient
{
    private array $availableTools = [];
    private array $serverInfo = [];
    private bool $connected = false;

    // stdio transport
    private $process = null;
    private array $pipes = [];

    // HTTP transport
    private ?string $sessionId = null;

    public function __construct(
        private readonly string $name,
        private readonly string $transport,
        private readonly array $config = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Create HTTP transport client
     */
    public static function http(string $name, string $endpoint, array $headers = [], ?LoggerInterface $logger = null): self
    {
        return new self($name, 'http', [
            'endpoint' => $endpoint,
            'headers' => $headers,
        ], $logger ?? new NullLogger());
    }

    /**
     * Create stdio transport client
     */
    public static function stdio(string $name, string $command, ?string $workingDir = null, array $env = [], ?LoggerInterface $logger = null): self
    {
        return new self($name, 'stdio', [
            'command' => $command,
            'working_dir' => $workingDir,
            'env' => $env,
        ], $logger ?? new NullLogger());
    }

    /**
     * Connect to the MCP server and fetch available tools
     */
    public function connect(): bool
    {
        $this->logger->info("Connecting to MCP server: {$this->name}", [
            'transport' => $this->transport
        ]);

        if ($this->transport === 'stdio') {
            if (!$this->connectStdio()) {
                return false;
            }
        }

        // Initialize MCP session
        $initResult = $this->sendRequest('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['call' => true],
            ],
            'clientInfo' => [
                'name' => 'fastmcphp-llm-bridge',
                'version' => '1.0.0',
            ],
        ]);

        if (!$initResult || isset($initResult['error'])) {
            $this->logger->error("Failed to initialize MCP connection", [
                'error' => $initResult['error'] ?? 'No response'
            ]);
            return false;
        }

        $this->serverInfo = $initResult['result']['serverInfo'] ?? [];
        $this->logger->info("Connected to MCP server", ['serverInfo' => $this->serverInfo]);

        // Send initialized notification
        $this->sendNotification('notifications/initialized', []);

        // Fetch available tools
        $toolsResult = $this->sendRequest('tools/list', []);
        if ($toolsResult && isset($toolsResult['result']['tools'])) {
            $this->availableTools = $toolsResult['result']['tools'];
            $this->logger->info("Fetched tools from MCP server", [
                'count' => count($this->availableTools)
            ]);
        }

        $this->connected = true;
        return true;
    }

    /**
     * Disconnect from the MCP server
     */
    public function disconnect(): void
    {
        if ($this->transport === 'stdio' && $this->process) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
            $this->process = null;
            $this->pipes = [];
        }
        $this->connected = false;
    }

    /**
     * Get available tools in Ollama/OpenAI format
     */
    public function getToolsForLlm(): array
    {
        $tools = [];
        foreach ($this->availableTools as $tool) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $this->name . '__' . $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => self::normalizeSchema($tool['inputSchema'] ?? null),
                ],
            ];
        }
        return $tools;
    }

    /**
     * Normalize JSON Schema for proper encoding
     * Ensures empty arrays become objects where required
     */
    private static function normalizeSchema(?array $schema): array
    {
        if ($schema === null) {
            return [
                'type' => 'object',
                'properties' => (object)[],
            ];
        }

        // Ensure properties is an object, not array
        if (isset($schema['properties'])) {
            if (is_array($schema['properties']) && empty($schema['properties'])) {
                $schema['properties'] = (object)[];
            } elseif (is_array($schema['properties'])) {
                // Recursively normalize nested schemas
                foreach ($schema['properties'] as $key => $prop) {
                    if (is_array($prop)) {
                        $schema['properties'][$key] = self::normalizeSchema($prop);
                    }
                }
                // Cast to object to preserve key order in JSON
                $schema['properties'] = (object)$schema['properties'];
            }
        } else {
            $schema['properties'] = (object)[];
        }

        // Ensure required is an array (not object)
        if (isset($schema['required']) && !is_array($schema['required'])) {
            $schema['required'] = [];
        }

        // Ensure definitions/defs are objects
        foreach (['definitions', '$defs'] as $defKey) {
            if (isset($schema[$defKey])) {
                if (is_array($schema[$defKey]) && empty($schema[$defKey])) {
                    $schema[$defKey] = (object)[];
                } elseif (is_array($schema[$defKey])) {
                    foreach ($schema[$defKey] as $key => $def) {
                        if (is_array($def)) {
                            $schema[$defKey][$key] = self::normalizeSchema($def);
                        }
                    }
                    $schema[$defKey] = (object)$schema[$defKey];
                }
            }
        }

        // Default type to object if not set
        if (!isset($schema['type'])) {
            $schema['type'] = 'object';
        }

        return $schema;
    }

    /**
     * Get raw MCP tool definitions
     */
    public function getTools(): array
    {
        return $this->availableTools;
    }

    /**
     * Call a tool on this MCP server
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        $this->logger->info("Calling MCP tool", [
            'server' => $this->name,
            'tool' => $toolName,
            'arguments' => $arguments,
        ]);

        $result = $this->sendRequest('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);

        if (!$result) {
            return [
                'success' => false,
                'error' => 'No response from MCP server',
            ];
        }

        if (isset($result['error'])) {
            return [
                'success' => false,
                'error' => $result['error']['message'] ?? 'Tool call failed',
                'code' => $result['error']['code'] ?? null,
            ];
        }

        $content = $result['result']['content'] ?? [];
        $textParts = [];
        foreach ($content as $item) {
            if (($item['type'] ?? '') === 'text') {
                $textParts[] = $item['text'];
            }
        }

        return [
            'success' => !($result['result']['isError'] ?? false),
            'content' => $content,
            'text' => implode("\n", $textParts),
            'isError' => $result['result']['isError'] ?? false,
        ];
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Get server name
     */
    public function getName(): string
    {
        return $this->name;
    }

    // ========================================
    // stdio Transport
    // ========================================

    private function connectStdio(): bool
    {
        $command = $this->config['command'] ?? null;
        if (!$command) {
            $this->logger->error("No command specified for stdio transport");
            return false;
        }

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $env = array_merge($_ENV, $this->config['env'] ?? []);
        $cwd = $this->config['working_dir'] ?? null;

        $this->process = proc_open($command, $descriptors, $this->pipes, $cwd, $env);

        if (!is_resource($this->process)) {
            $this->logger->error("Failed to start MCP server process", ['command' => $command]);
            return false;
        }

        // Set stdout to non-blocking for reading
        stream_set_blocking($this->pipes[1], false);

        $this->logger->info("Started MCP server process", ['command' => $command]);
        return true;
    }

    private function sendStdio(array $payload): ?array
    {
        if (!$this->process || !is_resource($this->pipes[0])) {
            return null;
        }

        $json = json_encode($payload) . "\n";
        fwrite($this->pipes[0], $json);
        fflush($this->pipes[0]);

        // Read response (with timeout)
        $response = '';
        $timeout = 30;
        $start = time();

        while ((time() - $start) < $timeout) {
            $line = fgets($this->pipes[1]);
            if ($line !== false) {
                $response = trim($line);
                break;
            }
            usleep(10000); // 10ms
        }

        if (empty($response)) {
            return null;
        }

        return json_decode($response, true);
    }

    // ========================================
    // HTTP Transport (Streamable HTTP)
    // ========================================

    private function sendHttp(array $payload): ?array
    {
        $endpoint = $this->config['endpoint'] ?? null;
        if (!$endpoint) {
            $this->logger->error("No endpoint specified for HTTP transport");
            return null;
        }

        $ch = curl_init($endpoint);

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $this->config['headers'] ?? []);

        if ($this->sessionId) {
            $headers[] = "Mcp-Session-Id: {$this->sessionId}";
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) {
                // Capture session ID from response headers
                if (stripos($header, 'Mcp-Session-Id:') === 0) {
                    $this->sessionId = trim(substr($header, 15));
                }
                return strlen($header);
            },
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("HTTP request failed", ['error' => $error]);
            return null;
        }

        if ($httpCode >= 400) {
            $this->logger->error("HTTP error", ['code' => $httpCode, 'response' => $response]);
            return null;
        }

        if (empty($response)) {
            return [];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("JSON decode error", ['response' => $response]);
            return null;
        }

        return $decoded;
    }

    // ========================================
    // Common Methods
    // ========================================

    /**
     * Send JSON-RPC request to MCP server
     */
    private function sendRequest(string $method, array $params): ?array
    {
        $requestId = uniqid('req_');
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => $method,
            'params' => $params,
        ];

        return match ($this->transport) {
            'stdio' => $this->sendStdio($payload),
            'http' => $this->sendHttp($payload),
            default => null,
        };
    }

    /**
     * Send JSON-RPC notification (no response expected)
     */
    private function sendNotification(string $method, array $params): void
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ];

        match ($this->transport) {
            'stdio' => $this->sendStdio($payload),
            'http' => $this->sendHttp($payload),
            default => null,
        };
    }
}
