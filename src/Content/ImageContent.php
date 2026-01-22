<?php

declare(strict_types=1);

namespace Fastmcphp\Content;

/**
 * Image content block (base64 encoded).
 */
final readonly class ImageContent implements ContentBlock
{
    public function __construct(
        public string $data,
        public string $mimeType = 'image/png',
    ) {}

    /**
     * Create from a file path.
     */
    public static function fromFile(string $path): self
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }

        $mimeType = mime_content_type($path) ?: 'image/png';

        return new self(
            data: base64_encode($data),
            mimeType: $mimeType,
        );
    }

    public function getType(): string
    {
        return 'image';
    }

    public function toArray(): array
    {
        return [
            'type' => 'image',
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];
    }
}
