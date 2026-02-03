<?php

declare(strict_types=1);

namespace Fastmcphp\Llm;

/**
 * Response from an LLM provider.
 */
class LlmResponse
{
    public function __construct(
        public readonly string $content = '',
        public readonly array $toolCalls = [],
        public readonly bool $success = true,
        public readonly ?string $error = null,
        public readonly string $finishReason = 'stop',
        public readonly array $usage = [],
        public readonly array $raw = [],
    ) {}

    /**
     * Check if response contains tool calls
     */
    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    /**
     * Get tool calls in normalized format
     *
     * @return array Array of ['id' => string, 'name' => string, 'arguments' => array]
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * Create a failed response
     */
    public static function error(string $message, array $raw = []): self
    {
        return new self(
            content: '',
            success: false,
            error: $message,
            finishReason: 'error',
            raw: $raw,
        );
    }

    /**
     * Create a successful text response
     */
    public static function text(string $content, array $usage = [], array $raw = []): self
    {
        return new self(
            content: $content,
            success: true,
            finishReason: 'stop',
            usage: $usage,
            raw: $raw,
        );
    }

    /**
     * Create a response with tool calls
     */
    public static function withToolCalls(array $toolCalls, string $content = '', array $raw = []): self
    {
        return new self(
            content: $content,
            toolCalls: $toolCalls,
            success: true,
            finishReason: 'tool_calls',
            raw: $raw,
        );
    }
}
