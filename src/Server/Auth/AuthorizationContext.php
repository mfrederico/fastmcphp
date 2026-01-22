<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Auth;

/**
 * Context for authorization checks.
 *
 * Contains information about the user and the resource being accessed.
 */
final readonly class AuthorizationContext
{
    public const TYPE_TOOL = 'tool';
    public const TYPE_RESOURCE = 'resource';
    public const TYPE_PROMPT = 'prompt';

    /** @var string Alias for $type */
    public string $componentType;

    /** @var string Alias for $name */
    public string $componentName;

    /**
     * @param AuthenticatedUser $user The authenticated user
     * @param string $type Resource type (tool, resource, prompt)
     * @param string $name Resource name/identifier
     * @param string $action Action being performed (call, read, get, list)
     * @param array<string, mixed> $arguments Arguments passed to the resource
     * @param string|null $workspace Current workspace
     */
    public function __construct(
        public AuthenticatedUser $user,
        public string $type,
        public string $name,
        public string $action,
        public array $arguments = [],
        public ?string $workspace = null,
    ) {
        $this->componentType = $type;
        $this->componentName = $name;
    }

    /**
     * Create context for a tool call.
     */
    public static function forTool(
        AuthenticatedUser $user,
        string $toolName,
        array $arguments = [],
        ?string $workspace = null,
    ): self {
        return new self(
            user: $user,
            type: self::TYPE_TOOL,
            name: $toolName,
            action: 'call',
            arguments: $arguments,
            workspace: $workspace,
        );
    }

    /**
     * Create context for a resource read.
     */
    public static function forResource(
        AuthenticatedUser $user,
        string $uri,
        ?string $workspace = null,
    ): self {
        return new self(
            user: $user,
            type: self::TYPE_RESOURCE,
            name: $uri,
            action: 'read',
            workspace: $workspace,
        );
    }

    /**
     * Create context for a prompt get.
     */
    public static function forPrompt(
        AuthenticatedUser $user,
        string $promptName,
        array $arguments = [],
        ?string $workspace = null,
    ): self {
        return new self(
            user: $user,
            type: self::TYPE_PROMPT,
            name: $promptName,
            action: 'get',
            arguments: $arguments,
            workspace: $workspace,
        );
    }

    /**
     * Get the scope string for this context (e.g., "tools:my_tool").
     */
    public function getScope(): string
    {
        $category = match ($this->type) {
            self::TYPE_TOOL => 'tools',
            self::TYPE_RESOURCE => 'resources',
            self::TYPE_PROMPT => 'prompts',
            default => $this->type,
        };

        return "{$category}:{$this->name}";
    }

    /**
     * Check if user has the required scope for this context.
     */
    public function hasScope(): bool
    {
        return $this->user->hasScope($this->getScope());
    }
}
