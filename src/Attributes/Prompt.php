<?php

declare(strict_types=1);

namespace Fastmcphp\Attributes;

use Attribute;

/**
 * Attribute to mark a function or method as an MCP prompt.
 *
 * Usage:
 *   #[Prompt(name: 'analyze', description: 'Analyze a topic')]
 *   function analyzePrompt(string $topic): array { ... }
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final readonly class Prompt
{
    /**
     * @param string|null $name Prompt name (defaults to function name)
     * @param string|null $description Prompt description
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
    ) {}
}
