<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Auth;

/**
 * Result of an authentication attempt.
 */
final readonly class AuthResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNAUTHENTICATED = 'unauthenticated';

    private function __construct(
        public string $status,
        public ?AuthenticatedUser $user = null,
        public ?string $error = null,
        public ?string $workspace = null,
    ) {}

    /**
     * Create a successful authentication result.
     */
    public static function success(AuthenticatedUser $user, ?string $workspace = null): self
    {
        return new self(
            status: self::STATUS_SUCCESS,
            user: $user,
            workspace: $workspace ?? $user->workspace,
        );
    }

    /**
     * Create a failed authentication result (bad credentials).
     */
    public static function failed(string $error = 'Authentication failed'): self
    {
        return new self(
            status: self::STATUS_FAILED,
            error: $error,
        );
    }

    /**
     * Create an unauthenticated result (no credentials provided).
     *
     * This is different from failed - it means no auth was attempted.
     */
    public static function unauthenticated(): self
    {
        return new self(
            status: self::STATUS_UNAUTHENTICATED,
        );
    }

    /**
     * Check if authentication succeeded.
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Alias for isSuccess().
     */
    public function isAuthenticated(): bool
    {
        return $this->isSuccess();
    }

    /**
     * Check if authentication failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if no authentication was attempted.
     */
    public function isUnauthenticated(): bool
    {
        return $this->status === self::STATUS_UNAUTHENTICATED;
    }

    /**
     * Get the authenticated user (throws if not successful).
     *
     * @throws \RuntimeException If authentication was not successful
     */
    public function getUser(): AuthenticatedUser
    {
        if (!$this->isSuccess() || $this->user === null) {
            throw new \RuntimeException('Cannot get user from unsuccessful auth result');
        }
        return $this->user;
    }
}
