<?php

declare(strict_types=1);

namespace Fastmcphp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;
use Fastmcphp\Server\Auth\AuthorizationContext;
use Fastmcphp\Server\Auth\AuthProviderInterface;

/**
 * Tests for authentication system.
 */
class AuthenticationTest extends TestCase
{
    // =========================================================================
    // AuthRequest Tests
    // =========================================================================

    public function testAuthRequestFromBearerToken(): void
    {
        $request = new AuthRequest(
            headers: ['authorization' => 'Bearer tk_test123'],
            query: [],
            body: '',
        );

        $this->assertEquals('tk_test123', $request->getBearerToken());
        $this->assertEquals('tk_test123', $request->getToken());
    }

    public function testAuthRequestFromApiTokenHeader(): void
    {
        $request = new AuthRequest(
            headers: ['x-api-token' => 'tk_apitoken456'],
            query: [],
            body: '',
        );

        $this->assertEquals('tk_apitoken456', $request->getApiToken());
        $this->assertEquals('tk_apitoken456', $request->getToken());
    }

    public function testAuthRequestFromQueryParam(): void
    {
        $request = new AuthRequest(
            headers: [],
            query: ['key' => 'tk_querykey789'],
            body: '',
        );

        $this->assertEquals('tk_querykey789', $request->getQueryToken());
        $this->assertEquals('tk_querykey789', $request->getToken());
    }

    public function testAuthRequestTokenPriority(): void
    {
        // X-API-TOKEN takes priority over Bearer (matching myctobot's order)
        $request = new AuthRequest(
            headers: [
                'authorization' => 'Bearer tk_bearer',
                'x-api-token' => 'tk_apitoken',
            ],
            query: ['key' => 'tk_query'],
            body: '',
        );

        $this->assertEquals('tk_apitoken', $request->getToken());
    }

    public function testAuthRequestApiTokenOverQuery(): void
    {
        // X-API-TOKEN takes priority over query param
        $request = new AuthRequest(
            headers: ['x-api-token' => 'tk_apitoken'],
            query: ['key' => 'tk_query'],
            body: '',
        );

        $this->assertEquals('tk_apitoken', $request->getToken());
    }

    public function testAuthRequestNoToken(): void
    {
        $request = new AuthRequest(
            headers: [],
            query: [],
            body: '',
        );

        $this->assertNull($request->getToken());
    }

    public function testAuthRequestBearerTokenCaseInsensitive(): void
    {
        // Use fromHttp which normalizes headers to lowercase
        $request = AuthRequest::fromHttp(
            headers: ['Authorization' => 'BEARER tk_uppercase'],
            query: [],
        );

        $this->assertEquals('tk_uppercase', $request->getBearerToken());
    }

    // =========================================================================
    // AuthResult Tests
    // =========================================================================

    public function testAuthResultSuccess(): void
    {
        $user = new AuthenticatedUser(
            id: '1',
            name: 'Test User',
            level: 100,
        );

        $result = AuthResult::success($user, 'test-workspace');

        $this->assertTrue($result->isAuthenticated());
        $this->assertNull($result->error);
        $this->assertEquals('test-workspace', $result->workspace);
        $this->assertSame($user, $result->user);
    }

    public function testAuthResultFailed(): void
    {
        $result = AuthResult::failed('Invalid token');

        $this->assertFalse($result->isAuthenticated());
        $this->assertEquals('Invalid token', $result->error);
        $this->assertNull($result->user);
    }

    public function testAuthResultUnauthenticated(): void
    {
        $result = AuthResult::unauthenticated();

        $this->assertFalse($result->isAuthenticated());
        $this->assertNull($result->error);
        $this->assertNull($result->user);
    }

    // =========================================================================
    // AuthenticatedUser Tests
    // =========================================================================

    public function testAuthenticatedUserBasicProperties(): void
    {
        $user = new AuthenticatedUser(
            id: '42',
            name: 'Alice',
            email: 'alice@example.com',
            level: 50,
            scopes: ['tools:*', 'resources:read'],
            workspace: 'demo',
            extra: ['member_id' => 42],
        );

        $this->assertEquals('42', $user->id);
        $this->assertEquals('Alice', $user->name);
        $this->assertEquals('alice@example.com', $user->email);
        $this->assertEquals(50, $user->level);
        $this->assertEquals('demo', $user->workspace);
        $this->assertEquals(['member_id' => 42], $user->extra);
    }

    public function testAuthenticatedUserHasLevel(): void
    {
        $adminUser = new AuthenticatedUser(id: '1', name: 'Admin', level: 50);
        $memberUser = new AuthenticatedUser(id: '2', name: 'Member', level: 100);

        // Lower level number = more privileges
        $this->assertTrue($adminUser->hasLevel(50));   // Exactly admin
        $this->assertTrue($adminUser->hasLevel(100));  // Admin can access member level
        $this->assertFalse($adminUser->hasLevel(1));   // Admin cannot access root

        $this->assertTrue($memberUser->hasLevel(100)); // Exactly member
        $this->assertFalse($memberUser->hasLevel(50)); // Member cannot access admin
    }

    public function testAuthenticatedUserHasScopeExact(): void
    {
        $user = new AuthenticatedUser(
            id: '1',
            name: 'Test',
            scopes: ['tools:echo', 'resources:config'],
        );

        $this->assertTrue($user->hasScope('tools:echo'));
        $this->assertTrue($user->hasScope('resources:config'));
        $this->assertFalse($user->hasScope('tools:admin'));
        $this->assertFalse($user->hasScope('prompts:explain'));
    }

    public function testAuthenticatedUserHasScopeWildcard(): void
    {
        $user = new AuthenticatedUser(
            id: '1',
            name: 'Test',
            scopes: ['tools:*'],
        );

        $this->assertTrue($user->hasScope('tools:echo'));
        $this->assertTrue($user->hasScope('tools:admin'));
        $this->assertTrue($user->hasScope('tools:anything'));
        $this->assertFalse($user->hasScope('resources:config'));
    }

    public function testAuthenticatedUserHasScopeFullWildcard(): void
    {
        $user = new AuthenticatedUser(
            id: '1',
            name: 'SuperAdmin',
            scopes: ['*:*'],
        );

        $this->assertTrue($user->hasScope('tools:echo'));
        $this->assertTrue($user->hasScope('resources:config'));
        $this->assertTrue($user->hasScope('prompts:explain'));
        $this->assertTrue($user->hasScope('anything:anything'));
    }

    public function testAuthenticatedUserNoScopes(): void
    {
        $user = new AuthenticatedUser(
            id: '1',
            name: 'No Scopes',
            scopes: [],
        );

        $this->assertFalse($user->hasScope('tools:echo'));
        $this->assertFalse($user->hasScope('anything:anything'));
    }

    // =========================================================================
    // AuthorizationContext Tests
    // =========================================================================

    public function testAuthorizationContextForTool(): void
    {
        $user = new AuthenticatedUser(id: '1', name: 'Test', level: 100);

        $ctx = AuthorizationContext::forTool($user, 'echo', [], 'demo');

        $this->assertEquals('tool', $ctx->componentType);
        $this->assertEquals('echo', $ctx->componentName);
        $this->assertSame($user, $ctx->user);
        $this->assertEquals('demo', $ctx->workspace);
    }

    public function testAuthorizationContextForResource(): void
    {
        $user = new AuthenticatedUser(id: '1', name: 'Test');

        $ctx = AuthorizationContext::forResource($user, 'config://app');

        $this->assertEquals('resource', $ctx->componentType);
        $this->assertEquals('config://app', $ctx->componentName);
    }

    public function testAuthorizationContextForPrompt(): void
    {
        $user = new AuthenticatedUser(id: '1', name: 'Test');

        $ctx = AuthorizationContext::forPrompt($user, 'explain');

        $this->assertEquals('prompt', $ctx->componentType);
        $this->assertEquals('explain', $ctx->componentName);
    }

    // =========================================================================
    // Custom Auth Provider Tests
    // =========================================================================

    public function testCustomAuthProviderSuccess(): void
    {
        $provider = new class implements AuthProviderInterface {
            public function authenticate(AuthRequest $request): AuthResult
            {
                $token = $request->getToken();

                if ($token === 'valid-token') {
                    return AuthResult::success(
                        new AuthenticatedUser(id: '1', name: 'Test User'),
                        'test-workspace'
                    );
                }

                return AuthResult::failed('Invalid token');
            }
        };

        $request = new AuthRequest(
            headers: ['authorization' => 'Bearer valid-token'],
            query: [],
            body: '',
        );

        $result = $provider->authenticate($request);

        $this->assertTrue($result->isAuthenticated());
        $this->assertEquals('Test User', $result->user->name);
        $this->assertEquals('test-workspace', $result->workspace);
    }

    public function testCustomAuthProviderFailure(): void
    {
        $provider = new class implements AuthProviderInterface {
            public function authenticate(AuthRequest $request): AuthResult
            {
                return AuthResult::failed('Access denied');
            }
        };

        $request = new AuthRequest(headers: [], query: [], body: '');
        $result = $provider->authenticate($request);

        $this->assertFalse($result->isAuthenticated());
        $this->assertEquals('Access denied', $result->error);
    }

    // =========================================================================
    // Token Format Tests (tk_ prefix validation)
    // =========================================================================

    public function testTkTokenFormat(): void
    {
        // Valid tk_ token format
        $validToken = 'tk_' . bin2hex(random_bytes(32));
        $this->assertStringStartsWith('tk_', $validToken);
        $this->assertEquals(67, strlen($validToken)); // tk_ (3) + 64 hex chars

        $request = new AuthRequest(
            headers: ['authorization' => "Bearer {$validToken}"],
            query: [],
            body: '',
        );

        $this->assertEquals($validToken, $request->getToken());
    }

    public function testValidateTkTokenFormat(): void
    {
        $validTokens = [
            'tk_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            'tk_0000000000000000000000000000000000000000000000000000000000000000',
            'tk_ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        ];

        foreach ($validTokens as $token) {
            $this->assertTrue(
                preg_match('/^tk_[a-f0-9]{64}$/', $token) === 1,
                "Token should match tk_ format: {$token}"
            );
        }
    }

    public function testInvalidTkTokenFormats(): void
    {
        $invalidTokens = [
            'invalid-token',
            'tk_short',
            'tk_', // too short
            'api_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', // wrong prefix
            'TK_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2', // uppercase prefix
        ];

        foreach ($invalidTokens as $token) {
            $this->assertFalse(
                preg_match('/^tk_[a-f0-9]{64}$/', $token) === 1,
                "Token should NOT match tk_ format: {$token}"
            );
        }
    }
}
