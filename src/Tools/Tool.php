<?php

declare(strict_types=1);

namespace Fastmcphp\Tools;

use Fastmcphp\Server\Context;

/**
 * Interface for MCP tools.
 */
interface Tool
{
    /**
     * Get the tool name.
     */
    public function getName(): string;

    /**
     * Get the tool description.
     */
    public function getDescription(): string;

    /**
     * Get the input parameters JSON schema.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * @param array<string, mixed> $arguments
     * @param Context|null $context Optional execution context
     */
    public function execute(array $arguments, ?Context $context = null): ToolResult;

    /**
     * Convert to MCP tool definition format.
     *
     * @return array<string, mixed>
     */
    public function toMcpTool(): array;
}
