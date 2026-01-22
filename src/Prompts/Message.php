<?php

declare(strict_types=1);

namespace Fastmcphp\Prompts;

use Fastmcphp\Content\ContentBlock;
use Fastmcphp\Content\TextContent;

/**
 * A message in a prompt.
 */
final readonly class Message
{
    /**
     * @param string $role Message role ('user' or 'assistant')
     * @param array<ContentBlock> $content Message content blocks
     */
    public function __construct(
        public string $role,
        public array $content,
    ) {}

    /**
     * Create a user message.
     */
    public static function user(string|ContentBlock $content): self
    {
        return new self(
            role: 'user',
            content: is_string($content) ? [new TextContent($content)] : [$content],
        );
    }

    /**
     * Create an assistant message.
     */
    public static function assistant(string|ContentBlock $content): self
    {
        return new self(
            role: 'assistant',
            content: is_string($content) ? [new TextContent($content)] : [$content],
        );
    }

    /**
     * Convert to MCP message format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content[0] instanceof TextContent
                ? ['type' => 'text', 'text' => $this->content[0]->text]
                : array_map(fn(ContentBlock $c) => $c->toArray(), $this->content),
        ];
    }
}
