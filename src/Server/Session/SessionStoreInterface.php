<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Session;

/**
 * Interface for persisting MCP session initialization state.
 *
 * Required for stateless transports (PHP-FPM) where the Server instance
 * is recreated on each request. Long-lived transports (stdio, Swoole)
 * can rely on in-memory state and don't need this.
 */
interface SessionStoreInterface
{
    /**
     * Check if a session has been initialized.
     */
    public function isInitialized(string $sessionId): bool;

    /**
     * Mark a session as initialized.
     *
     * @param int $ttl Time-to-live in seconds (default 30 minutes)
     */
    public function markInitialized(string $sessionId, int $ttl = 1800): void;
}
