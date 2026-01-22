<?php

declare(strict_types=1);

namespace Fastmcphp\Tools;

use Fastmcphp\Content\ContentBlock;
use Fastmcphp\Content\TextContent;

/**
 * Result of a tool execution.
 */
final readonly class ToolResult
{
    /**
     * @param array<ContentBlock> $content Content blocks
     * @param array<string, mixed>|null $structuredContent Structured output matching output_schema
     * @param array<string, mixed>|null $meta Runtime metadata
     * @param bool $isError Whether this result represents an error
     */
    public function __construct(
        public array $content = [],
        public ?array $structuredContent = null,
        public ?array $meta = null,
        public bool $isError = false,
    ) {}

    /**
     * Create a text result.
     */
    public static function text(string $text): self
    {
        return new self(content: [new TextContent($text)]);
    }

    /**
     * Create an error result.
     */
    public static function error(string $message): self
    {
        return new self(
            content: [new TextContent($message)],
            isError: true,
        );
    }

    /**
     * Create from a raw return value.
     */
    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof ContentBlock) {
            return new self(content: [$value]);
        }

        if (is_array($value) && isset($value[0]) && $value[0] instanceof ContentBlock) {
            return new self(content: $value);
        }

        if (is_string($value)) {
            return self::text($value);
        }

        if (is_bool($value)) {
            return self::text($value ? 'true' : 'false');
        }

        if (is_numeric($value)) {
            return self::text((string) $value);
        }

        if (is_array($value) || is_object($value)) {
            return self::text(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ($value === null) {
            return self::text('null');
        }

        return self::text((string) $value);
    }

    /**
     * Convert to MCP CallToolResult format.
     *
     * @return array<string, mixed>
     */
    public function toMcpResult(): array
    {
        $result = [
            'content' => array_map(
                fn(ContentBlock $block) => $block->toArray(),
                $this->content
            ),
        ];

        if ($this->isError) {
            $result['isError'] = true;
        }

        if ($this->structuredContent !== null) {
            $result['structuredContent'] = $this->structuredContent;
        }

        if ($this->meta !== null) {
            $result['_meta'] = $this->meta;
        }

        return $result;
    }
}
