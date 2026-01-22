<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Auth;

/**
 * Interface for authorization checks.
 *
 * Used for per-tool, per-resource, and per-prompt authorization.
 * Can be implemented as a class or provided as a callable.
 */
interface AuthorizationInterface
{
    /**
     * Check if the user is authorized for the given context.
     *
     * @param AuthorizationContext $context Authorization context with user and resource info
     * @return bool True if authorized, false otherwise
     */
    public function authorize(AuthorizationContext $context): bool;
}
