<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Transport;

use Fastmcphp\Server\Server;

/**
 * Interface for MCP transports.
 */
interface TransportInterface
{
    /**
     * Start the transport and begin handling messages.
     */
    public function run(Server $server): void;

    /**
     * Stop the transport.
     */
    public function stop(): void;
}
