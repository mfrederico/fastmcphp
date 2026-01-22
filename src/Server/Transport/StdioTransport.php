<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Transport;

use Fastmcphp\Protocol\JsonRpc;
use Fastmcphp\Protocol\JsonRpcException;
use Fastmcphp\Protocol\ErrorCodes;
use Fastmcphp\Server\Server;
use Swoole\Coroutine;

/**
 * Stdio transport for MCP - communicates via stdin/stdout.
 *
 * This transport is used when the MCP server runs as a subprocess
 * and communicates with the parent process via standard streams.
 */
class StdioTransport implements TransportInterface
{
    private bool $running = false;

    public function __construct(
        private readonly bool $useSwoole = true,
    ) {}

    public function run(Server $server): void
    {
        $this->running = true;

        if ($this->useSwoole && extension_loaded('swoole')) {
            $this->runWithSwoole($server);
        } else {
            $this->runBlocking($server);
        }
    }

    /**
     * Run with Swoole coroutines.
     */
    private function runWithSwoole(Server $server): void
    {
        Coroutine\run(function () use ($server) {
            // Set stdin to non-blocking
            stream_set_blocking(STDIN, false);

            $buffer = '';

            while ($this->running) {
                // Read from stdin
                $chunk = fread(STDIN, 8192);

                if ($chunk === false || $chunk === '') {
                    // No data available, yield to other coroutines
                    Coroutine::sleep(0.01);
                    continue;
                }

                $buffer .= $chunk;

                // Process complete lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $response = $this->processMessage($server, $line);
                    if ($response !== null) {
                        fwrite(STDOUT, $response . "\n");
                        fflush(STDOUT);
                    }
                }

                // Check if stdin is closed
                if (feof(STDIN)) {
                    break;
                }
            }
        });
    }

    /**
     * Run in blocking mode (without Swoole).
     */
    private function runBlocking(Server $server): void
    {
        while ($this->running && ($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $response = $this->processMessage($server, $line);
            if ($response !== null) {
                fwrite(STDOUT, $response . "\n");
                fflush(STDOUT);
            }
        }
    }

    /**
     * Process a single message and return the response.
     */
    private function processMessage(Server $server, string $line): ?string
    {
        try {
            $message = JsonRpc::parse($line);
            return $server->handle($message);
        } catch (JsonRpcException $e) {
            return JsonRpc::encodeError(
                null,
                $e->getCode(),
                $e->getMessage(),
                $e->data
            );
        } catch (\Throwable $e) {
            return JsonRpc::encodeError(
                null,
                ErrorCodes::INTERNAL_ERROR,
                'Internal error: ' . $e->getMessage()
            );
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
