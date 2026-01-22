<?php

declare(strict_types=1);

namespace Fastmcphp\Attributes;

use Attribute;

/**
 * Attribute to mark a function or method as an MCP resource.
 *
 * Usage:
 *   #[Resource(uri: 'resource://my-data', description: 'My data resource')]
 *   function getMyData(): string { ... }
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final readonly class Resource
{
    /**
     * @param string $uri Resource URI (can contain {placeholders} for templates)
     * @param string|null $name Resource name (defaults to function name)
     * @param string|null $description Resource description
     * @param string|null $mimeType MIME type of the content
     */
    public function __construct(
        public string $uri,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $mimeType = null,
    ) {}
}
