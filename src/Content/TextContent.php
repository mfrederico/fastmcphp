<?php

declare(strict_types=1);

namespace Fastmcphp\Content;

/**
 * Text content block.
 */
final readonly class TextContent implements ContentBlock
{
    public function __construct(
        public string $text,
    ) {}

    public function getType(): string
    {
        return 'text';
    }

    public function toArray(): array
    {
        return [
            'type' => 'text',
            'text' => $this->text,
        ];
    }
}
