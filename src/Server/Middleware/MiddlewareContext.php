<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Middleware;

use Fastmcphp\Protocol\Request;
use Fastmcphp\Protocol\Notification;
use Fastmcphp\Server\Auth\AuthenticatedUser;
use DateTimeImmutable;

/**
 * Context object passed through the middleware chain.
 *
 * Contains information about the current request and allows
 * middleware to share data with handlers.
 */
class MiddlewareContext
{
    /** @var array<string, mixed> Custom data shared between middleware */
    private array $attributes = [];

    public function __construct(
        public readonly Request|Notification $message,
        public readonly string $method,
        public readonly DateTimeImmutable $timestamp,
        public readonly ?AuthenticatedUser $user = null,
        public readonly ?string $workspace = null,
    ) {}

    /**
     * Get the request ID (for Request messages).
     */
    public function getRequestId(): string|int|null
    {
        return $this->message instanceof Request ? $this->message->id : null;
    }

    /**
     * Get a parameter from the message.
     */
    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->message->getParam($key, $default);
    }

    /**
     * Get all parameters from the message.
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->message->params;
    }

    /**
     * Get the tool name (for tools/call requests).
     */
    public function getToolName(): ?string
    {
        if ($this->method !== 'tools/call') {
            return null;
        }
        return $this->getParam('name');
    }

    /**
     * Get the resource URI (for resources/read requests).
     */
    public function getResourceUri(): ?string
    {
        if ($this->method !== 'resources/read') {
            return null;
        }
        return $this->getParam('uri');
    }

    /**
     * Get the prompt name (for prompts/get requests).
     */
    public function getPromptName(): ?string
    {
        if ($this->method !== 'prompts/get') {
            return null;
        }
        return $this->getParam('name');
    }

    /**
     * Check if the user is authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    /**
     * Set a custom attribute.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get a custom attribute.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Check if an attribute exists.
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Get all attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Create a new context with an authenticated user.
     */
    public function withUser(AuthenticatedUser $user): self
    {
        $new = clone $this;
        // Use reflection to set readonly property on clone
        $ref = new \ReflectionProperty($new, 'user');
        $ref->setValue($new, $user);
        return $new;
    }

    /**
     * Create a new context with a workspace.
     */
    public function withWorkspace(string $workspace): self
    {
        $new = clone $this;
        $ref = new \ReflectionProperty($new, 'workspace');
        $ref->setValue($new, $workspace);
        return $new;
    }
}
