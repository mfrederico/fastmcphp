<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Auth;

/**
 * Represents an authenticated user/member.
 *
 * This is a minimal interface that Fastmcphp uses internally.
 * Your application can extend this or create adapters from your
 * existing user/member models.
 */
class AuthenticatedUser
{
    /**
     * @param string $id Unique user identifier
     * @param string|null $name Display name
     * @param string|null $email Email address
     * @param int $level Permission level (lower = more privileged, e.g., 1=ROOT, 100=MEMBER)
     * @param array<string> $scopes Allowed scopes (e.g., ["tools:*", "resources:read"])
     * @param string|null $workspace Current workspace/tenant identifier
     * @param array<string, mixed> $extra Additional user data
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
        public readonly int $level = 100,
        public readonly array $scopes = [],
        public readonly ?string $workspace = null,
        public readonly array $extra = [],
    ) {}

    /**
     * Check if user has a specific scope.
     *
     * Supports wildcards: "tools:*" matches "tools:echo", "tools:add", etc.
     * "*:*" matches everything.
     */
    public function hasScope(string $scope): bool
    {
        // Full wildcard
        if (in_array('*:*', $this->scopes, true)) {
            return true;
        }

        // Exact match
        if (in_array($scope, $this->scopes, true)) {
            return true;
        }

        // Wildcard match (e.g., "tools:*" matches "tools:echo")
        [$category, $action] = explode(':', $scope) + [null, null];

        if ($category !== null) {
            // Check for category wildcard
            if (in_array("{$category}:*", $this->scopes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user's level is sufficient (lower = more privileged).
     */
    public function hasLevel(int $requiredLevel): bool
    {
        return $this->level <= $requiredLevel;
    }

    /**
     * Get an extra attribute.
     */
    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    /**
     * Create from an array (e.g., from session data).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: $data['name'] ?? $data['display_name'] ?? null,
            email: $data['email'] ?? null,
            level: (int) ($data['level'] ?? 100),
            scopes: $data['scopes'] ?? [],
            workspace: $data['workspace'] ?? null,
            extra: array_diff_key($data, array_flip(['id', 'name', 'display_name', 'email', 'level', 'scopes', 'workspace'])),
        );
    }
}
