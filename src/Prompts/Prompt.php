<?php

declare(strict_types=1);

namespace Fastmcphp\Prompts;

/**
 * Interface for MCP prompts.
 */
interface Prompt
{
    /**
     * Get the prompt name.
     */
    public function getName(): string;

    /**
     * Get the prompt description.
     */
    public function getDescription(): string;

    /**
     * Get the arguments schema.
     *
     * @return array<array<string, mixed>>
     */
    public function getArguments(): array;

    /**
     * Get the prompt messages.
     *
     * @param array<string, mixed> $arguments
     */
    public function get(array $arguments): PromptResult;

    /**
     * Convert to MCP prompt definition format.
     *
     * @return array<string, mixed>
     */
    public function toMcpPrompt(): array;
}
