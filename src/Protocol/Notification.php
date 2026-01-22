<?php

declare(strict_types=1);

namespace Fastmcphp\Protocol;

/**
 * Represents a JSON-RPC 2.0 notification (no id, no response expected).
 */
final readonly class Notification
{
    /**
     * @param string $method Method name
     * @param array<string, mixed> $params Method parameters
     * @param array<string, mixed>|null $meta Optional metadata
     */
    public function __construct(
        public string $method,
        public array $params = [],
        public ?array $meta = null,
    ) {}

    /**
     * Get a parameter value by key.
     */
    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Check if a parameter exists.
     */
    public function hasParam(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }
}
