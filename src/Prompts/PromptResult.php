<?php

declare(strict_types=1);

namespace Fastmcphp\Prompts;

/**
 * Result of getting a prompt.
 */
final readonly class PromptResult
{
    /**
     * @param array<Message> $messages Prompt messages
     * @param string|null $description Optional description
     */
    public function __construct(
        public array $messages,
        public ?string $description = null,
    ) {}

    /**
     * Create from raw messages.
     *
     * @param array<Message|string|array{role: string, content: string}> $messages
     */
    public static function fromMessages(array $messages): self
    {
        $normalized = [];

        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $normalized[] = $message;
            } elseif (is_string($message)) {
                $normalized[] = Message::user($message);
            } elseif (is_array($message)) {
                $role = $message['role'];
                $content = $message['content'];
                $normalized[] = $role === 'assistant'
                    ? Message::assistant($content)
                    : Message::user($content);
            }
        }

        return new self($normalized);
    }

    /**
     * Convert to MCP prompt result format.
     *
     * @return array<string, mixed>
     */
    public function toMcpResult(): array
    {
        $result = [
            'messages' => array_map(
                fn(Message $m) => $m->toArray(),
                $this->messages
            ),
        ];

        if ($this->description !== null) {
            $result['description'] = $this->description;
        }

        return $result;
    }
}
