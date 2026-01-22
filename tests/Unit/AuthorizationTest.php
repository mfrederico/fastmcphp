<?php

declare(strict_types=1);

namespace Fastmcphp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fastmcphp\Fastmcphp;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;
use Fastmcphp\Server\Auth\AuthorizationContext;
use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Prompts\Message;
use Fastmcphp\Protocol\Request;
use Fastmcphp\Protocol\JsonRpcException;

/**
 * Tests for per-component authorization.
 */
class AuthorizationTest extends TestCase
{
    private Fastmcphp $mcp;

    /** @var array<string, AuthenticatedUser> */
    private array $users;

    protected function setUp(): void
    {
        // Create test users
        $this->users = [
            'root' => new AuthenticatedUser(
                id: '1',
                name: 'Root User',
                level: 1,
                scopes: ['*:*'],
            ),
            'admin' => new AuthenticatedUser(
                id: '2',
                name: 'Admin User',
                level: 50,
                scopes: ['tools:*', 'resources:admin'],
            ),
            'member' => new AuthenticatedUser(
                id: '3',
                name: 'Member User',
                level: 100,
                scopes: ['tools:echo', 'tools:workspace_tool', 'resources:public'],
            ),
            'limited' => new AuthenticatedUser(
                id: '4',
                name: 'Limited User',
                level: 100,
                scopes: ['tools:echo'],
            ),
        ];

        // Create MCP server with mock auth
        $this->mcp = new Fastmcphp('Test Server');
        $this->mcp->setAuth($this->createAuthProvider(), required: true);

        // Register components with various auth requirements
        $this->registerComponents();
    }

    private function createAuthProvider(): AuthProviderInterface
    {
        $users = $this->users;

        return new class($users) implements AuthProviderInterface {
            /** @param array<string, AuthenticatedUser> $users */
            public function __construct(private array $users) {}

            public function authenticate(AuthRequest $request): AuthResult
            {
                $token = $request->getToken();

                if ($token === null) {
                    return AuthResult::unauthenticated();
                }

                // Token format: user-{role}
                $role = str_replace('user-', '', $token);

                if (!isset($this->users[$role])) {
                    return AuthResult::failed('Unknown user');
                }

                return AuthResult::success($this->users[$role], 'test-workspace');
            }
        };
    }

    private function registerComponents(): void
    {
        // =====================================================================
        // Tools with different auth requirements
        // =====================================================================

        // Public tool - any authenticated user
        $this->mcp->tool(
            callable: fn(string $text) => $text,
            name: 'echo',
            description: 'Public tool',
        );

        // Admin-level tool
        $this->mcp->tool(
            callable: fn() => 'admin data',
            name: 'admin_tool',
            auth: fn(AuthorizationContext $ctx) => $ctx->user->hasLevel(50),
        );

        // Root-level tool
        $this->mcp->tool(
            callable: fn() => 'root data',
            name: 'root_tool',
            auth: fn(AuthorizationContext $ctx) => $ctx->user->hasLevel(1),
        );

        // Scope-based tool
        $this->mcp->tool(
            callable: fn() => 'special data',
            name: 'special_tool',
            auth: fn(AuthorizationContext $ctx) => $ctx->user->hasScope('tools:special'),
        );

        // Combined requirement - level AND scope
        $this->mcp->tool(
            callable: fn() => 'secure data',
            name: 'secure_tool',
            auth: fn(AuthorizationContext $ctx) =>
                $ctx->user->hasLevel(50) && $ctx->user->hasScope('tools:secure_tool'),
        );

        // Workspace-specific tool
        $this->mcp->tool(
            callable: fn() => 'workspace data',
            name: 'workspace_tool',
            auth: fn(AuthorizationContext $ctx) => $ctx->workspace === 'test-workspace',
        );

        // =====================================================================
        // Resources with auth requirements
        // =====================================================================

        // Public resource
        $this->mcp->resource(
            uri: 'config://public',
            callable: fn() => ['public' => true],
            auth: fn(AuthorizationContext $ctx) => $ctx->user->hasScope('resources:public'),
        );

        // Admin resource
        $this->mcp->resource(
            uri: 'config://admin',
            callable: fn() => ['admin' => true],
            auth: fn(AuthorizationContext $ctx) => $ctx->user->hasScope('resources:admin'),
        );

        // Resource template with auth
        $this->mcp->resource(
            uri: 'users://{id}',
            callable: fn(int $id) => ['id' => $id, 'name' => "User {$id}"],
            auth: fn(AuthorizationContext $ctx) => $ctx->user->hasLevel(50),
        );

        // =====================================================================
        // Prompts with auth requirements
        // =====================================================================

        // Public prompt
        $this->mcp->prompt(
            callable: fn(string $topic) => [Message::user("Explain {$topic}")],
            name: 'explain',
        );

        // Admin prompt
        $this->mcp->prompt(
            callable: fn() => [Message::user('Admin prompt')],
            name: 'admin_prompt',
            auth: fn(AuthorizationContext $ctx) => $ctx->user->hasLevel(50),
        );
    }

    // =========================================================================
    // Tool Authorization Tests
    // =========================================================================

    public function testPublicToolAccessibleByAll(): void
    {
        foreach (['root', 'admin', 'member', 'limited'] as $role) {
            $response = $this->callTool('echo', ['text' => 'test'], "user-{$role}");
            $this->assertEquals('test', $response['content'][0]['text']);
        }
    }

    public function testAdminToolAccessibleByAdmin(): void
    {
        $response = $this->callTool('admin_tool', [], 'user-admin');
        $this->assertEquals('admin data', $response['content'][0]['text']);
    }

    public function testAdminToolAccessibleByRoot(): void
    {
        $response = $this->callTool('admin_tool', [], 'user-root');
        $this->assertEquals('admin data', $response['content'][0]['text']);
    }

    public function testAdminToolDeniedToMember(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->callTool('admin_tool', [], 'user-member');
    }

    public function testRootToolAccessibleByRoot(): void
    {
        $response = $this->callTool('root_tool', [], 'user-root');
        $this->assertEquals('root data', $response['content'][0]['text']);
    }

    public function testRootToolDeniedToAdmin(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->callTool('root_tool', [], 'user-admin');
    }

    public function testScopeBasedToolAccessWithWildcard(): void
    {
        // Admin has tools:* which matches tools:special
        $response = $this->callTool('special_tool', [], 'user-admin');
        $this->assertEquals('special data', $response['content'][0]['text']);
    }

    public function testScopeBasedToolDeniedWithoutScope(): void
    {
        // Member only has tools:echo, not tools:special
        $this->expectException(JsonRpcException::class);
        $this->callTool('special_tool', [], 'user-member');
    }

    public function testWorkspaceToolAccess(): void
    {
        $response = $this->callTool('workspace_tool', [], 'user-member');
        $this->assertEquals('workspace data', $response['content'][0]['text']);
    }

    // =========================================================================
    // Resource Authorization Tests
    // =========================================================================

    public function testPublicResourceAccess(): void
    {
        $response = $this->readResource('config://public', 'user-member');
        $this->assertTrue($response['contents'][0]['text'] !== '');
    }

    public function testPublicResourceDeniedWithoutScope(): void
    {
        // Limited user doesn't have resources:public scope
        $this->expectException(JsonRpcException::class);
        $this->readResource('config://public', 'user-limited');
    }

    public function testAdminResourceAccess(): void
    {
        $response = $this->readResource('config://admin', 'user-admin');
        $this->assertNotEmpty($response['contents']);
    }

    public function testAdminResourceDeniedToMember(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->readResource('config://admin', 'user-member');
    }

    public function testResourceTemplateAuthApplied(): void
    {
        // Admin can access user template
        $response = $this->readResource('users://42', 'user-admin');
        $content = json_decode($response['contents'][0]['text'], true);
        $this->assertEquals(42, $content['id']);
    }

    public function testResourceTemplateDeniedToMember(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->readResource('users://42', 'user-member');
    }

    // =========================================================================
    // Prompt Authorization Tests
    // =========================================================================

    public function testPublicPromptAccess(): void
    {
        $response = $this->getPrompt('explain', ['topic' => 'PHP'], 'user-member');
        $this->assertNotEmpty($response['messages']);
    }

    public function testAdminPromptAccess(): void
    {
        $response = $this->getPrompt('admin_prompt', [], 'user-admin');
        $this->assertNotEmpty($response['messages']);
    }

    public function testAdminPromptDeniedToMember(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->getPrompt('admin_prompt', [], 'user-member');
    }

    // =========================================================================
    // Authorization Context Tests
    // =========================================================================

    public function testAuthorizationContextForTool(): void
    {
        $ctx = AuthorizationContext::forTool($this->users['admin'], 'test_tool', [], 'workspace');

        $this->assertEquals('tool', $ctx->componentType);
        $this->assertEquals('test_tool', $ctx->componentName);
        $this->assertEquals('Admin User', $ctx->user->name);
        $this->assertEquals('workspace', $ctx->workspace);
    }

    public function testAuthorizationContextForResource(): void
    {
        $ctx = AuthorizationContext::forResource($this->users['member'], 'config://test');

        $this->assertEquals('resource', $ctx->componentType);
        $this->assertEquals('config://test', $ctx->componentName);
    }

    public function testAuthorizationContextForPrompt(): void
    {
        $ctx = AuthorizationContext::forPrompt($this->users['root'], 'test_prompt');

        $this->assertEquals('prompt', $ctx->componentType);
        $this->assertEquals('test_prompt', $ctx->componentName);
    }

    // =========================================================================
    // Complex Authorization Scenarios
    // =========================================================================

    public function testCombinedLevelAndScopeRequirementPasses(): void
    {
        // Create user with both level 50 and tools:secure_tool scope
        $specialUser = new AuthenticatedUser(
            id: '5',
            name: 'Special User',
            level: 50,
            scopes: ['tools:secure_tool'],
        );

        // Temporarily add this user to the auth provider
        $mcp = new Fastmcphp('Test');
        $mcp->setAuth(new class($specialUser) implements AuthProviderInterface {
            public function __construct(private AuthenticatedUser $user) {}

            public function authenticate(AuthRequest $request): AuthResult
            {
                return AuthResult::success($this->user);
            }
        });

        $mcp->tool(
            callable: fn() => 'secure',
            name: 'secure_tool',
            auth: fn(AuthorizationContext $ctx) =>
                $ctx->user->hasLevel(50) && $ctx->user->hasScope('tools:secure_tool'),
        );

        // Initialize first
        $this->handleMcpRequest($mcp, 'initialize', [], 'any-token');

        $response = $this->handleMcpRequest($mcp, 'tools/call', [
            'name' => 'secure_tool',
            'arguments' => [],
        ], 'any-token');

        $this->assertEquals('secure', $response['content'][0]['text']);
    }

    public function testCombinedLevelAndScopeRequirementFailsOnLevel(): void
    {
        // User with scope but wrong level
        $user = new AuthenticatedUser(
            id: '6',
            name: 'Level Fail',
            level: 100, // Not admin
            scopes: ['tools:secure_tool'],
        );

        $mcp = new Fastmcphp('Test');
        $mcp->setAuth(new class($user) implements AuthProviderInterface {
            public function __construct(private AuthenticatedUser $user) {}

            public function authenticate(AuthRequest $request): AuthResult
            {
                return AuthResult::success($this->user);
            }
        });

        $mcp->tool(
            callable: fn() => 'secure',
            name: 'secure_tool',
            auth: fn(AuthorizationContext $ctx) =>
                $ctx->user->hasLevel(50) && $ctx->user->hasScope('tools:secure_tool'),
        );

        $this->expectException(JsonRpcException::class);
        $this->handleMcpRequest($mcp, 'tools/call', [
            'name' => 'secure_tool',
            'arguments' => [],
        ], 'any-token');
    }

    // =========================================================================
    // Tools List Filtering Tests
    // =========================================================================

    public function testToolsListShowsOnlyAuthorizedTools(): void
    {
        $response = $this->handleRequest('tools/list', [], 'user-member');

        $toolNames = array_column($response['tools'], 'name');

        // Member should see public tools
        $this->assertContains('echo', $toolNames);
        $this->assertContains('workspace_tool', $toolNames);

        // But not admin-only tools
        $this->assertNotContains('admin_tool', $toolNames);
        $this->assertNotContains('root_tool', $toolNames);
    }

    public function testToolsListShowsAllForAdmin(): void
    {
        $response = $this->handleRequest('tools/list', [], 'user-admin');

        $toolNames = array_column($response['tools'], 'name');

        $this->assertContains('echo', $toolNames);
        $this->assertContains('admin_tool', $toolNames);
        $this->assertContains('special_tool', $toolNames);
        // But not root-only
        $this->assertNotContains('root_tool', $toolNames);
    }

    public function testToolsListShowsAllForRoot(): void
    {
        $response = $this->handleRequest('tools/list', [], 'user-root');

        $toolNames = array_column($response['tools'], 'name');

        $this->assertContains('echo', $toolNames);
        $this->assertContains('admin_tool', $toolNames);
        $this->assertContains('root_tool', $toolNames);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function initialize(string $token): void
    {
        $this->handleRequest('initialize', [], $token);
    }

    private function callTool(string $name, array $arguments, string $token): array
    {
        $this->initialize($token);
        return $this->handleRequest('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ], $token);
    }

    private function readResource(string $uri, string $token): array
    {
        $this->initialize($token);
        return $this->handleRequest('resources/read', ['uri' => $uri], $token);
    }

    private function getPrompt(string $name, array $arguments, string $token): array
    {
        $this->initialize($token);
        return $this->handleRequest('prompts/get', [
            'name' => $name,
            'arguments' => $arguments,
        ], $token);
    }

    private function handleRequest(string $method, array $params, string $token): array
    {
        return $this->handleMcpRequest($this->mcp, $method, $params, $token);
    }

    private function handleMcpRequest(Fastmcphp $mcp, string $method, array $params, string $token): array
    {
        $request = new Request(
            id: 1,
            method: $method,
            params: $params,
        );

        $authRequest = new AuthRequest(
            headers: ['authorization' => "Bearer {$token}"],
            query: [],
            body: '',
        );

        $response = $mcp->getServer()->handle($request, $authRequest);

        if ($response === null) {
            return [];
        }

        $decoded = json_decode($response, true);

        if (isset($decoded['error'])) {
            throw new JsonRpcException(
                $decoded['error']['message'],
                $decoded['error']['code']
            );
        }

        return $decoded['result'] ?? [];
    }
}
