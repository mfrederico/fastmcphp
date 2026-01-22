<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Auth;

/**
 * Interface for authentication providers.
 *
 * Implement this interface to integrate your authentication system
 * with Fastmcphp. The provider is responsible for:
 *
 * 1. Extracting credentials from requests (headers, tokens, etc.)
 * 2. Validating credentials against your auth system
 * 3. Returning an AuthenticatedUser on success
 *
 * Example implementation for Bearer tokens:
 *
 *   class BearerTokenAuthProvider implements AuthProviderInterface
 *   {
 *       public function authenticate(AuthRequest $request): AuthResult
 *       {
 *           $token = $request->getBearerToken();
 *           if (!$token) {
 *               return AuthResult::unauthenticated();
 *           }
 *
 *           $apiKey = $this->apiKeyService->validate($token);
 *           if (!$apiKey) {
 *               return AuthResult::failed('Invalid token');
 *           }
 *
 *           return AuthResult::success(new AuthenticatedUser(
 *               id: $apiKey->member_id,
 *               scopes: $apiKey->scopes,
 *               workspace: $apiKey->workspace,
 *           ));
 *       }
 *   }
 */
interface AuthProviderInterface
{
    /**
     * Authenticate a request.
     *
     * @param AuthRequest $request The auth request containing headers/context
     * @return AuthResult The authentication result
     */
    public function authenticate(AuthRequest $request): AuthResult;
}
