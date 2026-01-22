<?php

declare(strict_types=1);

namespace Fastmcphp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fastmcphp\Fastmcphp;
use Fastmcphp\Server\Server;
use Fastmcphp\Server\Context;
use Fastmcphp\Tools\FunctionTool;
use Fastmcphp\Tools\ToolResult;
use Fastmcphp\Resources\FunctionResource;
use Fastmcphp\Resources\FunctionResourceTemplate;
use Fastmcphp\Prompts\FunctionPrompt;
use Fastmcphp\Prompts\Message;
use Fastmcphp\Prompts\PromptResult;
use Fastmcphp\Protocol\JsonRpc;
use Fastmcphp\Protocol\Request;

/**
 * Tests for MCP protocol capabilities.
 */
class McpCapabilitiesTest extends TestCase
{
    private Fastmcphp $mcp;
    private Server $server;

    protected function setUp(): void
    {
        $this->mcp = new Fastmcphp('Test Server', '1.0.0');
        $this->server = $this->mcp->getServer();
    }

    // =========================================================================
    // Tool Tests
    // =========================================================================

    public function testToolRegistration(): void
    {
        $this->mcp->tool(
            callable: fn(string $text) => $text,
            name: 'echo',
            description: 'Echo text back',
        );

        $response = $this->handleRequest('tools/list', []);

        $this->assertArrayHasKey('tools', $response);
        $this->assertCount(1, $response['tools']);
        $this->assertEquals('echo', $response['tools'][0]['name']);
        $this->assertEquals('Echo text back', $response['tools'][0]['description']);
    }

    public function testToolWithMultipleParameters(): void
    {
        $this->mcp->tool(
            callable: fn(int $a, int $b) => $a + $b,
            name: 'add',
            description: 'Add two numbers',
        );

        $response = $this->handleRequest('tools/list', []);

        $schema = $response['tools'][0]['inputSchema'];
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('a', $schema['properties']);
        $this->assertArrayHasKey('b', $schema['properties']);
        $this->assertEquals('integer', $schema['properties']['a']['type']);
        $this->assertEquals('integer', $schema['properties']['b']['type']);
        $this->assertContains('a', $schema['required']);
        $this->assertContains('b', $schema['required']);
    }

    public function testToolExecution(): void
    {
        $this->mcp->tool(
            callable: fn(int $a, int $b) => $a + $b,
            name: 'add',
        );

        $this->initialize();
        $response = $this->handleRequest('tools/call', [
            'name' => 'add',
            'arguments' => ['a' => 5, 'b' => 3],
        ]);

        $this->assertArrayHasKey('content', $response);
        $this->assertEquals('8', $response['content'][0]['text']);
    }

    public function testToolWithOptionalParameters(): void
    {
        $this->mcp->tool(
            callable: fn(string $name, string $greeting = 'Hello') => "{$greeting}, {$name}!",
            name: 'greet',
        );

        $response = $this->handleRequest('tools/list', []);

        $schema = $response['tools'][0]['inputSchema'];
        $this->assertContains('name', $schema['required']);
        $this->assertNotContains('greeting', $schema['required'] ?? []);
    }

    public function testToolWithContextInjection(): void
    {
        $this->mcp->tool(
            callable: function(string $query, Context $ctx): array {
                $ctx->info("Searching: {$query}");
                return ['query' => $query, 'results' => []];
            },
            name: 'search',
        );

        $response = $this->handleRequest('tools/list', []);

        // Context parameter should not appear in schema
        $schema = $response['tools'][0]['inputSchema'];
        $this->assertArrayHasKey('query', $schema['properties']);
        $this->assertArrayNotHasKey('ctx', $schema['properties']);
    }

    public function testToolWithArrayReturn(): void
    {
        $this->mcp->tool(
            callable: fn() => ['items' => [1, 2, 3], 'count' => 3],
            name: 'get_items',
        );

        $this->initialize();
        $response = $this->handleRequest('tools/call', [
            'name' => 'get_items',
            'arguments' => [],
        ]);

        $this->assertArrayHasKey('content', $response);
        $content = json_decode($response['content'][0]['text'], true);
        $this->assertEquals([1, 2, 3], $content['items']);
    }

    public function testToolNotFound(): void
    {
        $this->initialize();

        $this->expectException(\Fastmcphp\Protocol\JsonRpcException::class);
        $this->handleRequest('tools/call', [
            'name' => 'nonexistent',
            'arguments' => [],
        ]);
    }

    // =========================================================================
    // Resource Tests
    // =========================================================================

    public function testStaticResourceRegistration(): void
    {
        $this->mcp->resource(
            uri: 'config://app',
            callable: fn() => ['version' => '1.0.0'],
            name: 'app_config',
            description: 'Application configuration',
        );

        $response = $this->handleRequest('resources/list', []);

        $this->assertArrayHasKey('resources', $response);
        $this->assertCount(1, $response['resources']);
        $this->assertEquals('config://app', $response['resources'][0]['uri']);
        $this->assertEquals('app_config', $response['resources'][0]['name']);
    }

    public function testResourceRead(): void
    {
        $this->mcp->resource(
            uri: 'config://app',
            callable: fn() => ['version' => '2.0.0', 'name' => 'TestApp'],
        );

        $this->initialize();
        $response = $this->handleRequest('resources/read', [
            'uri' => 'config://app',
        ]);

        $this->assertArrayHasKey('contents', $response);
        $content = json_decode($response['contents'][0]['text'], true);
        $this->assertEquals('2.0.0', $content['version']);
    }

    public function testResourceTemplateRegistration(): void
    {
        $this->mcp->resource(
            uri: 'users://{id}',
            callable: fn(int $id) => ['id' => $id, 'name' => "User {$id}"],
            name: 'user',
            description: 'Get user by ID',
        );

        $response = $this->handleRequest('resources/templates/list', []);

        $this->assertArrayHasKey('resourceTemplates', $response);
        $this->assertCount(1, $response['resourceTemplates']);
        $this->assertEquals('users://{id}', $response['resourceTemplates'][0]['uriTemplate']);
    }

    public function testResourceTemplateRead(): void
    {
        $this->mcp->resource(
            uri: 'users://{id}',
            callable: fn(int $id) => ['id' => $id, 'name' => "User {$id}"],
        );

        $this->initialize();
        $response = $this->handleRequest('resources/read', [
            'uri' => 'users://42',
        ]);

        $this->assertArrayHasKey('contents', $response);
        $content = json_decode($response['contents'][0]['text'], true);
        $this->assertEquals(42, $content['id']);
        $this->assertEquals('User 42', $content['name']);
    }

    public function testResourceWithMimeType(): void
    {
        $this->mcp->resource(
            uri: 'data://sample.json',
            callable: fn() => '{"key": "value"}',
            mimeType: 'application/json',
        );

        $response = $this->handleRequest('resources/list', []);

        $this->assertEquals('application/json', $response['resources'][0]['mimeType']);
    }

    public function testResourceNotFound(): void
    {
        $this->initialize();

        $this->expectException(\Fastmcphp\Protocol\JsonRpcException::class);
        $this->handleRequest('resources/read', [
            'uri' => 'nonexistent://resource',
        ]);
    }

    // =========================================================================
    // Prompt Tests
    // =========================================================================

    public function testPromptRegistration(): void
    {
        $this->mcp->prompt(
            callable: fn(string $topic) => [
                Message::user("Explain {$topic} in simple terms"),
            ],
            name: 'explain',
            description: 'Get an explanation of a topic',
        );

        $response = $this->handleRequest('prompts/list', []);

        $this->assertArrayHasKey('prompts', $response);
        $this->assertCount(1, $response['prompts']);
        $this->assertEquals('explain', $response['prompts'][0]['name']);
    }

    public function testPromptGet(): void
    {
        $this->mcp->prompt(
            callable: fn(string $topic) => [
                Message::user("Explain {$topic}"),
                Message::assistant("I'll explain {$topic} for you."),
            ],
            name: 'explain',
        );

        $this->initialize();
        $response = $this->handleRequest('prompts/get', [
            'name' => 'explain',
            'arguments' => ['topic' => 'quantum computing'],
        ]);

        $this->assertArrayHasKey('messages', $response);
        $this->assertCount(2, $response['messages']);
        $this->assertEquals('user', $response['messages'][0]['role']);
        $this->assertStringContainsString('quantum computing', $response['messages'][0]['content']['text']);
    }

    public function testPromptWithMultipleArguments(): void
    {
        $this->mcp->prompt(
            callable: fn(string $language, string $level) => [
                Message::user("Create a {$level} tutorial for {$language}"),
            ],
            name: 'tutorial',
        );

        $response = $this->handleRequest('prompts/list', []);

        $args = $response['prompts'][0]['arguments'];
        $this->assertCount(2, $args);

        $argNames = array_column($args, 'name');
        $this->assertContains('language', $argNames);
        $this->assertContains('level', $argNames);
    }

    public function testPromptNotFound(): void
    {
        $this->initialize();

        $this->expectException(\Fastmcphp\Protocol\JsonRpcException::class);
        $this->handleRequest('prompts/get', [
            'name' => 'nonexistent',
            'arguments' => [],
        ]);
    }

    // =========================================================================
    // Protocol Tests
    // =========================================================================

    public function testInitialize(): void
    {
        $response = $this->handleRequest('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'Test Client', 'version' => '1.0.0'],
        ]);

        $this->assertArrayHasKey('protocolVersion', $response);
        $this->assertArrayHasKey('capabilities', $response);
        $this->assertArrayHasKey('serverInfo', $response);
        $this->assertEquals('Test Server', $response['serverInfo']['name']);
    }

    public function testCapabilitiesReflectRegistrations(): void
    {
        // Register one of each
        $this->mcp->tool(callable: fn() => 'ok', name: 'test_tool');
        $this->mcp->resource(uri: 'test://resource', callable: fn() => 'data');
        $this->mcp->prompt(callable: fn() => [Message::user('test')], name: 'test_prompt');

        $response = $this->handleRequest('initialize', []);

        $capabilities = (array) $response['capabilities'];
        $this->assertArrayHasKey('tools', $capabilities);
        $this->assertArrayHasKey('resources', $capabilities);
        $this->assertArrayHasKey('prompts', $capabilities);
    }

    public function testPing(): void
    {
        $this->initialize();
        $response = $this->handleRequest('ping', []);

        $this->assertArrayHasKey('pong', $response);
        $this->assertTrue($response['pong']);
    }

    public function testMethodNotFound(): void
    {
        $this->initialize();

        $this->expectException(\Fastmcphp\Protocol\JsonRpcException::class);
        $this->handleRequest('unknown/method', []);
    }

    // =========================================================================
    // Multiple Components Tests
    // =========================================================================

    public function testMultipleToolsRegistration(): void
    {
        $this->mcp->tool(callable: fn(int $a, int $b) => $a + $b, name: 'add');
        $this->mcp->tool(callable: fn(int $a, int $b) => $a - $b, name: 'subtract');
        $this->mcp->tool(callable: fn(int $a, int $b) => $a * $b, name: 'multiply');

        $response = $this->handleRequest('tools/list', []);

        $this->assertCount(3, $response['tools']);
        $names = array_column($response['tools'], 'name');
        $this->assertContains('add', $names);
        $this->assertContains('subtract', $names);
        $this->assertContains('multiply', $names);
    }

    public function testMixedResourcesAndTemplates(): void
    {
        $this->mcp->resource(uri: 'config://app', callable: fn() => []);
        $this->mcp->resource(uri: 'users://{id}', callable: fn(int $id) => []);
        $this->mcp->resource(uri: 'posts://{userId}/{postId}', callable: fn(int $userId, int $postId) => []);

        $resourcesResponse = $this->handleRequest('resources/list', []);
        $templatesResponse = $this->handleRequest('resources/templates/list', []);

        $this->assertCount(1, $resourcesResponse['resources']);
        $this->assertCount(2, $templatesResponse['resourceTemplates']);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function initialize(): void
    {
        $this->handleRequest('initialize', []);
    }

    private function handleRequest(string $method, array $params): array
    {
        $request = new Request(
            id: 1,
            method: $method,
            params: $params,
        );

        $response = $this->server->handle($request);

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
