<?php

declare(strict_types=1);

namespace Fastmcphp\Resources;

/**
 * Result of reading a resource.
 */
final readonly class ResourceResult
{
    public function __construct(
        public string $uri,
        public string|array $content,
        public ?string $mimeType = null,
    ) {}

    /**
     * Create a text resource result.
     */
    public static function text(string $uri, string $content): self
    {
        return new self(
            uri: $uri,
            content: $content,
            mimeType: 'text/plain',
        );
    }

    /**
     * Create a JSON resource result.
     *
     * @param array<mixed> $data
     */
    public static function json(string $uri, array $data): self
    {
        return new self(
            uri: $uri,
            content: json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            mimeType: 'application/json',
        );
    }

    /**
     * Create from a raw value.
     */
    public static function fromValue(string $uri, mixed $value, ?string $mimeType = null): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            return new self(
                uri: $uri,
                content: $value,
                mimeType: $mimeType ?? 'text/plain',
            );
        }

        if (is_array($value) || is_object($value)) {
            return new self(
                uri: $uri,
                content: json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                mimeType: $mimeType ?? 'application/json',
            );
        }

        return new self(
            uri: $uri,
            content: (string) $value,
            mimeType: $mimeType ?? 'text/plain',
        );
    }

    /**
     * Convert to MCP resource content format.
     *
     * @return array<string, mixed>
     */
    public function toMcpContent(): array
    {
        $result = [
            'uri' => $this->uri,
        ];

        if (is_string($this->content)) {
            $result['text'] = $this->content;
        } else {
            $result['blob'] = base64_encode(json_encode($this->content));
        }

        if ($this->mimeType !== null) {
            $result['mimeType'] = $this->mimeType;
        }

        return $result;
    }
}
