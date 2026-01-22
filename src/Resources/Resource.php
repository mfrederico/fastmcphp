<?php

declare(strict_types=1);

namespace Fastmcphp\Resources;

/**
 * Interface for MCP resources.
 */
interface Resource
{
    /**
     * Get the resource URI.
     */
    public function getUri(): string;

    /**
     * Get the resource name.
     */
    public function getName(): string;

    /**
     * Get the resource description.
     */
    public function getDescription(): string;

    /**
     * Get the MIME type.
     */
    public function getMimeType(): ?string;

    /**
     * Read the resource content.
     */
    public function read(): ResourceResult;

    /**
     * Convert to MCP resource definition format.
     *
     * @return array<string, mixed>
     */
    public function toMcpResource(): array;
}
