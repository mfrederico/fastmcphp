<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Session;

/**
 * APCu-backed session store for MCP initialization state.
 *
 * Suitable for PHP-FPM deployments where the Server instance is
 * recreated on each request but APCu persists across requests.
 */
class ApcuSessionStore implements SessionStoreInterface
{
    private const KEY_PREFIX = 'fastmcphp_session:';

    public function isInitialized(string $sessionId): bool
    {
        if (!function_exists('apcu_fetch')) {
            return false;
        }

        $success = false;
        apcu_fetch(self::KEY_PREFIX . $sessionId, $success);
        return $success;
    }

    public function markInitialized(string $sessionId, int $ttl = 1800): void
    {
        if (!function_exists('apcu_store')) {
            return;
        }

        apcu_store(self::KEY_PREFIX . $sessionId, true, $ttl);
    }
}
