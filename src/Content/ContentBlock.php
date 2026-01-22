<?php

declare(strict_types=1);

namespace Fastmcphp\Content;

/**
 * Base interface for MCP content blocks.
 */
interface ContentBlock
{
    /**
     * Get the content type identifier.
     */
    public function getType(): string;

    /**
     * Convert to MCP wire format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
