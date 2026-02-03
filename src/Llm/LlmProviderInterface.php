<?php

declare(strict_types=1);

namespace Fastmcphp\Llm;

/**
 * Interface for LLM providers that support tool calling.
 */
interface LlmProviderInterface
{
    /**
     * Send a chat completion request with optional tools.
     *
     * @param array $messages Conversation messages
     * @param array $tools Available tools in provider format
     * @param array $options Provider-specific options
     * @return LlmResponse
     */
    public function chat(array $messages, array $tools = [], array $options = []): LlmResponse;

    /**
     * Send a streaming chat completion request.
     *
     * @param array $messages Conversation messages
     * @param array $tools Available tools
     * @param callable $onChunk Called with each text chunk
     * @param callable|null $onToolCall Called when tool call is requested
     * @param array $options Provider-specific options
     * @return LlmResponse Final response after stream completes
     */
    public function chatStream(
        array $messages,
        array $tools = [],
        callable $onChunk = null,
        ?callable $onToolCall = null,
        array $options = []
    ): LlmResponse;

    /**
     * Get the provider name
     */
    public function getName(): string;

    /**
     * Check if provider is available/configured
     */
    public function isAvailable(): bool;
}
