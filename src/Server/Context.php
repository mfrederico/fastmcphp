<?php

declare(strict_types=1);

namespace Fastmcphp\Server;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Request context available to tools, resources, and prompts.
 */
class Context
{
    /** @var array<string, mixed> */
    private array $state = [];

    public function __construct(
        private readonly ?string $requestId = null,
        private readonly ?string $clientId = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Get the request ID.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Get the client ID.
     */
    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    /**
     * Log a debug message.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    /**
     * Log an info message.
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /**
     * Log a warning message.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /**
     * Log an error message.
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    /**
     * Set a state value.
     */
    public function setState(string $key, mixed $value): void
    {
        $this->state[$key] = $value;
    }

    /**
     * Get a state value.
     */
    public function getState(string $key, mixed $default = null): mixed
    {
        return $this->state[$key] ?? $default;
    }

    /**
     * Check if a state key exists.
     */
    public function hasState(string $key): bool
    {
        return array_key_exists($key, $this->state);
    }

    /**
     * Remove a state value.
     */
    public function removeState(string $key): void
    {
        unset($this->state[$key]);
    }

    /**
     * Get all state values.
     *
     * @return array<string, mixed>
     */
    public function getAllState(): array
    {
        return $this->state;
    }
}
