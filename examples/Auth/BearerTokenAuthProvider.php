<?php

/**
 * Example Bearer Token Auth Provider
 *
 * This is a reference implementation showing how to create an auth provider
 * compatible with myctobot's ApiAuthService pattern.
 *
 * In your actual myctobot integration, you would implement this interface
 * and call your existing ApiAuthService.
 */

declare(strict_types=1);

namespace Fastmcphp\Examples\Auth;

use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;

/**
 * Bearer token authentication provider.
 *
 * Validates API tokens and returns authenticated users with scopes.
 * This is designed to match myctobot's ApiAuthService pattern.
 */
class BearerTokenAuthProvider implements AuthProviderInterface
{
    /**
     * @param callable $tokenValidator Function to validate tokens: fn(string $token): ?array
     *                                  Should return ['member' => [...], 'apikey' => [...], 'workspace' => '...'] or null
     */
    public function __construct(
        private readonly mixed $tokenValidator,
    ) {}

    public function authenticate(AuthRequest $request): AuthResult
    {
        // Extract token from multiple sources (matches myctobot's ApiAuthService)
        $token = $request->getToken();

        if ($token === null) {
            return AuthResult::unauthenticated();
        }

        // Validate token using the provided validator
        $result = ($this->tokenValidator)($token);

        if ($result === null) {
            return AuthResult::failed('Invalid or expired token');
        }

        // Extract member and API key data
        $member = $result['member'] ?? [];
        $apiKey = $result['apikey'] ?? [];
        $workspace = $result['workspace'] ?? null;

        // Parse scopes from API key (myctobot stores as JSON)
        $scopes = [];
        if (isset($apiKey['scopes_json'])) {
            $scopes = is_string($apiKey['scopes_json'])
                ? json_decode($apiKey['scopes_json'], true) ?? []
                : $apiKey['scopes_json'];
        } elseif (isset($apiKey['scopes'])) {
            $scopes = $apiKey['scopes'];
        }

        // Create authenticated user
        $user = new AuthenticatedUser(
            id: (string) ($member['id'] ?? $apiKey['member_id'] ?? ''),
            name: $member['display_name'] ?? $member['username'] ?? null,
            email: $member['email'] ?? null,
            level: (int) ($member['level'] ?? 100),
            scopes: $scopes,
            workspace: $workspace,
            extra: [
                'member' => $member,
                'apikey' => $apiKey,
            ],
        );

        return AuthResult::success($user, $workspace);
    }

    /**
     * Create a provider with a simple token lookup table (for testing).
     *
     * @param array<string, array{member: array, apikey?: array, workspace?: string}> $tokens
     */
    public static function withTokens(array $tokens): self
    {
        return new self(fn(string $token) => $tokens[$token] ?? null);
    }
}
