<?php

declare(strict_types=1);

namespace Fastmcphp\Attributes;

use Attribute;

/**
 * Attribute to mark a function or method as an MCP tool.
 *
 * Usage:
 *   #[Tool(name: 'my_tool', description: 'Does something useful')]
 *   function myTool(string $input): string { ... }
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final readonly class Tool
{
    /**
     * @param string|null $name Tool name (defaults to function name)
     * @param string|null $description Tool description (defaults to docblock)
     * @param array<string>|null $tags Categorization tags
     * @param float|null $timeout Execution timeout in seconds
     * @param bool $enabled Whether the tool is enabled
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?array $tags = null,
        public ?float $timeout = null,
        public bool $enabled = true,
    ) {}
}
