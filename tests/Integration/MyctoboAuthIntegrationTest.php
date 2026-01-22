<?php

declare(strict_types=1);

namespace Fastmcphp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Fastmcphp\Fastmcphp;
use Fastmcphp\Server\Server;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthorizationContext;
use Fastmcphp\Protocol\Request;
use Fastmcphp\Examples\Auth\MyctoboAuthProvider;
use Fastmcphp\Examples\Auth\MyctoboLevels;
use PDO;

/**
 * Integration tests for Myctobot authentication with tk_ keys.
 *
 * Uses an in-memory SQLite database to simulate myctobot's tables.
 */
class MyctoboAuthIntegrationTest extends TestCase
{
    private PDO $pdo;
    private MyctoboAuthProvider $authProvider;
    private Fastmcphp $mcp;

    /** @var array<string, string> Test tokens */
    private array $testTokens = [];

    protected function setUp(): void
    {
        // Create in-memory SQLite database
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tables matching myctobot schema
        $this->createTables();

        // Create test data
        $this->seedTestData();

        // Create auth provider with SQLite config
        $this->authProvider = new MyctoboAuthProvider(
            dbConfig: [
                'host' => '',
                'port' => 0,
                'name' => ':memory:',
                'user' => '',
                'pass' => '',
                'type' => 'sqlite',
            ],
            workspace: 'test-workspace',
            controller: 'mcp',
            method: 'call',
        );
        $this->authProvider->setConnection($this->pdo);

        // Create MCP server with auth
        $this->mcp = new Fastmcphp('Test Server', '1.0.0');
        $this->mcp->setAuth($this->authProvider, required: true);

        // Register test tools
        $this->registerTestTools();
    }

    private function createTables(): void
    {
        // Member table
        $this->pdo->exec("
            CREATE TABLE member (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                display_name TEXT,
                email TEXT,
                level INTEGER DEFAULT 100,
                status TEXT DEFAULT 'active'
            )
        ");

        // API keys table
        $this->pdo->exec("
            CREATE TABLE apikeys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                member_id INTEGER NOT NULL,
                token TEXT NOT NULL UNIQUE,
                name TEXT,
                scopes_json TEXT,
                expires_at TEXT,
                is_active INTEGER DEFAULT 1,
                last_used_at TEXT,
                last_used_ip TEXT,
                usage_count INTEGER DEFAULT 0,
                FOREIGN KEY (member_id) REFERENCES member(id)
            )
        ");
    }

    private function seedTestData(): void
    {
        // Create test members
        $this->pdo->exec("
            INSERT INTO member (id, username, display_name, email, level, status)
            VALUES
                (1, 'admin', 'Admin User', 'admin@test.com', 50, 'active'),
                (2, 'member', 'Regular Member', 'member@test.com', 100, 'active'),
                (3, 'inactive', 'Inactive User', 'inactive@test.com', 100, 'inactive'),
                (4, 'root', 'Root User', 'root@test.com', 1, 'active')
        ");

        // Generate test tokens
        $this->testTokens = [
            'admin' => 'tk_' . bin2hex(random_bytes(32)),
            'member' => 'tk_' . bin2hex(random_bytes(32)),
            'limited' => 'tk_' . bin2hex(random_bytes(32)),
            'expired' => 'tk_' . bin2hex(random_bytes(32)),
            'disabled' => 'tk_' . bin2hex(random_bytes(32)),
            'inactive_user' => 'tk_' . bin2hex(random_bytes(32)),
            'root' => 'tk_' . bin2hex(random_bytes(32)),
            'no_scope' => 'tk_' . bin2hex(random_bytes(32)),
        ];

        // Insert API keys
        $stmt = $this->pdo->prepare("
            INSERT INTO apikeys (member_id, token, name, scopes_json, expires_at, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        // Admin token - full access
        $stmt->execute([
            1,
            $this->testTokens['admin'],
            'Admin Key',
            json_encode(['*:*']),
            null,
            1,
        ]);

        // Member token - mcp:* scope
        $stmt->execute([
            2,
            $this->testTokens['member'],
            'Member Key',
            json_encode(['mcp:*', 'tools:*']),
            null,
            1,
        ]);

        // Limited token - only mcp:call
        $stmt->execute([
            2,
            $this->testTokens['limited'],
            'Limited Key',
            json_encode(['mcp:call']),
            null,
            1,
        ]);

        // Expired token
        $stmt->execute([
            2,
            $this->testTokens['expired'],
            'Expired Key',
            json_encode(['*:*']),
            '2020-01-01 00:00:00',
            1,
        ]);

        // Disabled token
        $stmt->execute([
            2,
            $this->testTokens['disabled'],
            'Disabled Key',
            json_encode(['*:*']),
            null,
            0,
        ]);

        // Token for inactive user
        $stmt->execute([
            3,
            $this->testTokens['inactive_user'],
            'Inactive User Key',
            json_encode(['*:*']),
            null,
            1,
        ]);

        // Root token
        $stmt->execute([
            4,
            $this->testTokens['root'],
            'Root Key',
            json_encode(['*:*']),
            null,
            1,
        ]);

        // Token without required scope
        $stmt->execute([
            2,
            $this->testTokens['no_scope'],
            'No Scope Key',
            json_encode(['other:method']),
            null,
            1,
        ]);
    }

    private function registerTestTools(): void
    {
        // Public tool (no special auth)
        $this->mcp->tool(
            callable: fn(string $text) => $text,
            name: 'echo',
            description: 'Echo text back',
        );

        // Admin-only tool
        $this->mcp->tool(
            callable: fn() => 'admin data',
            name: 'admin_tool',
            description: 'Admin only tool',
            auth: fn(AuthorizationContext $ctx) => $ctx->user->hasLevel(MyctoboLevels::ADMIN),
        );

        // Root-only tool
        $this->mcp->tool(
            callable: fn() => 'root data',
            name: 'root_tool',
            description: 'Root only tool',
            auth: fn(AuthorizationContext $ctx) => $ctx->user->hasLevel(MyctoboLevels::ROOT),
        );

        // Scope-based tool
        $this->mcp->tool(
            callable: fn() => 'special data',
            name: 'scoped_tool',
            description: 'Requires specific scope',
            auth: fn(AuthorizationContext $ctx) => $ctx->user->hasScope('tools:special'),
        );
    }

    // =========================================================================
    // Token Validation Tests
    // =========================================================================

    public function testValidAdminToken(): void
    {
        $result = $this->authenticate($this->testTokens['admin']);

        $this->assertTrue($result->isAuthenticated());
        $this->assertEquals('Admin User', $result->user->name);
        $this->assertEquals(50, $result->user->level);
        $this->assertEquals('test-workspace', $result->workspace);
    }

    public function testValidMemberToken(): void
    {
        $result = $this->authenticate($this->testTokens['member']);

        $this->assertTrue($result->isAuthenticated());
        $this->assertEquals('Regular Member', $result->user->name);
        $this->assertEquals(100, $result->user->level);
    }

    public function testInvalidTokenFormat(): void
    {
        $result = $this->authenticate('invalid-token');

        $this->assertFalse($result->isAuthenticated());
        $this->assertEquals('Invalid token format', $result->error);
    }

    public function testInvalidTokenShort(): void
    {
        $result = $this->authenticate('tk_short');

        $this->assertFalse($result->isAuthenticated());
        $this->assertEquals('Invalid token format', $result->error);
    }

    public function testNonexistentToken(): void
    {
        $fakeToken = 'tk_' . str_repeat('0', 64);
        $result = $this->authenticate($fakeToken);

        $this->assertFalse($result->isAuthenticated());
        $this->assertEquals('Invalid token', $result->error);
    }

    public function testExpiredToken(): void
    {
        $result = $this->authenticate($this->testTokens['expired']);

        $this->assertFalse($result->isAuthenticated());
        $this->assertEquals('Token has expired', $result->error);
    }

    public function testDisabledToken(): void
    {
        $result = $this->authenticate($this->testTokens['disabled']);

        $this->assertFalse($result->isAuthenticated());
        $this->assertEquals('Token is disabled', $result->error);
    }

    public function testInactiveUserToken(): void
    {
        $result = $this->authenticate($this->testTokens['inactive_user']);

        $this->assertFalse($result->isAuthenticated());
        $this->assertEquals('Member account is not active', $result->error);
    }

    public function testNoToken(): void
    {
        $result = $this->authProvider->authenticate(new AuthRequest());

        $this->assertFalse($result->isAuthenticated());
        $this->assertNull($result->error); // Unauthenticated, not failed
    }

    // =========================================================================
    // Scope Tests
    // =========================================================================

    public function testFullWildcardScope(): void
    {
        $result = $this->authenticate($this->testTokens['admin']);

        $this->assertTrue($result->isAuthenticated());
        $this->assertTrue($result->user->hasScope('anything:anything'));
        $this->assertTrue($result->user->hasScope('mcp:call'));
    }

    public function testControllerWildcardScope(): void
    {
        $result = $this->authenticate($this->testTokens['member']);

        $this->assertTrue($result->isAuthenticated());
        $this->assertTrue($result->user->hasScope('mcp:call'));
        $this->assertTrue($result->user->hasScope('mcp:anything'));
        $this->assertTrue($result->user->hasScope('tools:echo'));
        $this->assertFalse($result->user->hasScope('resources:read'));
    }

    public function testExactScope(): void
    {
        $result = $this->authenticate($this->testTokens['limited']);

        $this->assertTrue($result->isAuthenticated());
        $this->assertTrue($result->user->hasScope('mcp:call'));
        $this->assertFalse($result->user->hasScope('mcp:other'));
    }

    public function testInsufficientScope(): void
    {
        $result = $this->authenticate($this->testTokens['no_scope']);

        $this->assertFalse($result->isAuthenticated());
        $this->assertEquals('Insufficient scope for this operation', $result->error);
    }

    // =========================================================================
    // Permission Level Tests
    // =========================================================================

    public function testAdminLevelAccess(): void
    {
        $result = $this->authenticate($this->testTokens['admin']);

        $this->assertTrue($result->user->hasLevel(MyctoboLevels::ADMIN));
        $this->assertTrue($result->user->hasLevel(MyctoboLevels::MEMBER));
        $this->assertFalse($result->user->hasLevel(MyctoboLevels::ROOT));
    }

    public function testMemberLevelAccess(): void
    {
        $result = $this->authenticate($this->testTokens['member']);

        $this->assertTrue($result->user->hasLevel(MyctoboLevels::MEMBER));
        $this->assertFalse($result->user->hasLevel(MyctoboLevels::ADMIN));
    }

    public function testRootLevelAccess(): void
    {
        $result = $this->authenticate($this->testTokens['root']);

        $this->assertTrue($result->user->hasLevel(MyctoboLevels::ROOT));
        $this->assertTrue($result->user->hasLevel(MyctoboLevels::ADMIN));
        $this->assertTrue($result->user->hasLevel(MyctoboLevels::MEMBER));
    }

    // =========================================================================
    // MCP Server Integration Tests
    // =========================================================================

    public function testMcpToolsListWithAuth(): void
    {
        $response = $this->mcpRequest(
            'tools/list',
            [],
            $this->testTokens['member']
        );

        $this->assertArrayHasKey('tools', $response);
        $this->assertGreaterThanOrEqual(1, count($response['tools']));
    }

    public function testMcpToolsListWithoutAuth(): void
    {
        $this->expectException(\Fastmcphp\Protocol\JsonRpcException::class);
        $this->mcpRequest('tools/list', []);
    }

    public function testMcpToolCallWithAuth(): void
    {
        $this->mcpInitialize($this->testTokens['member']);

        $response = $this->mcpRequest(
            'tools/call',
            ['name' => 'echo', 'arguments' => ['text' => 'Hello!']],
            $this->testTokens['member']
        );

        $this->assertArrayHasKey('content', $response);
        $this->assertEquals('Hello!', $response['content'][0]['text']);
    }

    public function testMcpAdminToolWithAdminToken(): void
    {
        $this->mcpInitialize($this->testTokens['admin']);

        $response = $this->mcpRequest(
            'tools/call',
            ['name' => 'admin_tool', 'arguments' => []],
            $this->testTokens['admin']
        );

        $this->assertArrayHasKey('content', $response);
        $this->assertEquals('admin data', $response['content'][0]['text']);
    }

    public function testMcpAdminToolWithMemberTokenFails(): void
    {
        $this->mcpInitialize($this->testTokens['member']);

        $this->expectException(\Fastmcphp\Protocol\JsonRpcException::class);
        $this->mcpRequest(
            'tools/call',
            ['name' => 'admin_tool', 'arguments' => []],
            $this->testTokens['member']
        );
    }

    public function testMcpRootToolWithRootToken(): void
    {
        $this->mcpInitialize($this->testTokens['root']);

        $response = $this->mcpRequest(
            'tools/call',
            ['name' => 'root_tool', 'arguments' => []],
            $this->testTokens['root']
        );

        $this->assertArrayHasKey('content', $response);
        $this->assertEquals('root data', $response['content'][0]['text']);
    }

    public function testMcpRootToolWithAdminTokenFails(): void
    {
        $this->mcpInitialize($this->testTokens['admin']);

        $this->expectException(\Fastmcphp\Protocol\JsonRpcException::class);
        $this->mcpRequest(
            'tools/call',
            ['name' => 'root_tool', 'arguments' => []],
            $this->testTokens['admin']
        );
    }

    // =========================================================================
    // Usage Tracking Tests
    // =========================================================================

    public function testUsageCountIncremented(): void
    {
        $initialCount = $this->getUsageCount($this->testTokens['admin']);

        $this->authenticate($this->testTokens['admin']);

        $newCount = $this->getUsageCount($this->testTokens['admin']);
        $this->assertEquals($initialCount + 1, $newCount);
    }

    public function testLastUsedAtUpdated(): void
    {
        $this->authenticate($this->testTokens['member']);

        $stmt = $this->pdo->prepare('SELECT last_used_at FROM apikeys WHERE token = ?');
        $stmt->execute([$this->testTokens['member']]);
        $result = $stmt->fetch();

        $this->assertNotNull($result['last_used_at']);
    }

    // =========================================================================
    // Token Source Tests
    // =========================================================================

    public function testAuthFromBearerHeader(): void
    {
        $request = new AuthRequest(
            headers: ['authorization' => 'Bearer ' . $this->testTokens['member']],
            query: [],
            body: '',
        );

        $result = $this->authProvider->authenticate($request);
        $this->assertTrue($result->isAuthenticated());
    }

    public function testAuthFromApiTokenHeader(): void
    {
        $request = new AuthRequest(
            headers: ['x-api-token' => $this->testTokens['member']],
            query: [],
            body: '',
        );

        $result = $this->authProvider->authenticate($request);
        $this->assertTrue($result->isAuthenticated());
    }

    public function testAuthFromQueryParam(): void
    {
        $request = new AuthRequest(
            headers: [],
            query: ['key' => $this->testTokens['member']],
            body: '',
        );

        $result = $this->authProvider->authenticate($request);
        $this->assertTrue($result->isAuthenticated());
    }

    // =========================================================================
    // Workspace Tests
    // =========================================================================

    public function testWorkspaceFromConfig(): void
    {
        $result = $this->authenticate($this->testTokens['member']);

        $this->assertEquals('test-workspace', $result->workspace);
        $this->assertEquals('test-workspace', $result->user->workspace);
    }

    public function testWorkspaceFromHeader(): void
    {
        // Create provider without fixed workspace
        $provider = new MyctoboAuthProvider(
            dbConfig: ['host' => '', 'port' => 0, 'name' => ':memory:', 'user' => '', 'pass' => '', 'type' => 'sqlite'],
            workspace: null,
        );
        $provider->setConnection($this->pdo);

        $request = new AuthRequest(
            headers: [
                'x-api-token' => $this->testTokens['member'],
                'x-workspace' => 'header-workspace',
            ],
            query: [],
            body: '',
        );

        $result = $provider->authenticate($request);
        $this->assertTrue($result->isAuthenticated());
        $this->assertEquals('header-workspace', $result->workspace);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function authenticate(string $token): \Fastmcphp\Server\Auth\AuthResult
    {
        $request = new AuthRequest(
            headers: ['authorization' => "Bearer {$token}"],
            query: [],
            body: '',
        );

        return $this->authProvider->authenticate($request);
    }

    private function mcpInitialize(string $token): void
    {
        $this->mcpRequest('initialize', [], $token);
    }

    private function mcpRequest(string $method, array $params, ?string $token = null): array
    {
        $request = new Request(
            id: 1,
            method: $method,
            params: $params,
        );

        $authRequest = $token !== null
            ? new AuthRequest(headers: ['authorization' => "Bearer {$token}"], query: [], body: '')
            : new AuthRequest();

        $response = $this->mcp->getServer()->handle($request, $authRequest);

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

    private function getUsageCount(string $token): int
    {
        $stmt = $this->pdo->prepare('SELECT usage_count FROM apikeys WHERE token = ?');
        $stmt->execute([$token]);
        $result = $stmt->fetch();
        return (int) ($result['usage_count'] ?? 0);
    }
}
