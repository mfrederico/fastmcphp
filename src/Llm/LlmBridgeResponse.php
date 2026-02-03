<?php

declare(strict_types=1);

namespace Fastmcphp\Llm;

/**
 * Response from the LLM Bridge after processing a chat request.
 */
class LlmBridgeResponse
{
    public function __construct(
        public readonly string $content,
        public readonly bool $success = true,
        public readonly ?string $error = null,
        public readonly array $toolCalls = [],
        public readonly int $iterations = 1,
    ) {}

    /**
     * Check if any tools were called
     */
    public function usedTools(): bool
    {
        return !empty($this->toolCalls);
    }

    /**
     * Get the final text response
     */
    public function getText(): string
    {
        return $this->content;
    }
}
