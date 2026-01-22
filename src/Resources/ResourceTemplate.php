<?php

declare(strict_types=1);

namespace Fastmcphp\Resources;

use Fastmcphp\Utilities\UriTemplate;

/**
 * Interface for MCP resource templates.
 */
interface ResourceTemplate
{
    /**
     * Get the URI template pattern.
     */
    public function getUriTemplate(): string;

    /**
     * Get the template name.
     */
    public function getName(): string;

    /**
     * Get the template description.
     */
    public function getDescription(): string;

    /**
     * Get the MIME type.
     */
    public function getMimeType(): ?string;

    /**
     * Try to match a URI against this template.
     *
     * @return array<string, string>|null Extracted parameters or null if no match
     */
    public function matchUri(string $uri): ?array;

    /**
     * Read the resource with the given parameters.
     *
     * @param array<string, string> $params
     */
    public function read(array $params): ResourceResult;

    /**
     * Convert to MCP resource template definition format.
     *
     * @return array<string, mixed>
     */
    public function toMcpResourceTemplate(): array;
}
