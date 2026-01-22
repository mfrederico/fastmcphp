<?php

declare(strict_types=1);

namespace Fastmcphp\Protocol;

/**
 * Represents a JSON-RPC 2.0 request (has an id, expects a response).
 */
final readonly class Request
{
    /**
     * @param string|int $id Request identifier
     * @param string $method Method name
     * @param array<string, mixed> $params Method parameters
     * @param array<string, mixed>|null $meta Optional metadata
     */
    public function __construct(
        public string|int $id,
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
