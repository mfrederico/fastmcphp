<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Middleware;

use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Protocol\JsonRpcException;
use Fastmcphp\Protocol\ErrorCodes;

/**
 * Middleware that handles authentication.
 *
 * Extracts credentials from requests and authenticates users
 * before allowing access to protected resources.
 */
class AuthenticationMiddleware extends Middleware
{
    /**
     * @param AuthProviderInterface $authProvider The authentication provider
     * @param bool $required Whether authentication is required (rejects unauthenticated)
     * @param array<string> $publicMethods Methods that don't require auth
     */
    public function __construct(
        private readonly AuthProviderInterface $authProvider,
        private readonly bool $required = true,
        private readonly array $publicMethods = ['initialize', 'ping'],
    ) {}

    public function onRequest(MiddlewareContext $context, callable $next): mixed
    {
        // Skip auth for public methods
        if (in_array($context->method, $this->publicMethods, true)) {
            return $next($context);
        }

        // Get auth request from context attributes (set by transport)
        $authRequest = $context->getAttribute('authRequest');
        if (!$authRequest instanceof AuthRequest) {
            $authRequest = AuthRequest::empty();
        }

        // Authenticate
        $result = $this->authProvider->authenticate($authRequest);

        if ($result->isFailed()) {
            throw new JsonRpcException(
                $result->error ?? 'Authentication failed',
                ErrorCodes::UNAUTHORIZED
            );
        }

        if ($result->isUnauthenticated() && $this->required) {
            throw new JsonRpcException(
                'Authentication required',
                ErrorCodes::UNAUTHORIZED
            );
        }

        // Add user to context if authenticated
        if ($result->isSuccess()) {
            $context = $context->withUser($result->getUser());

            if ($result->workspace !== null) {
                $context = $context->withWorkspace($result->workspace);
            }
        }

        return $next($context);
    }
}
