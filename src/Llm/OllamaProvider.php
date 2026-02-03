<?php

declare(strict_types=1);

namespace Fastmcphp\Llm;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Ollama LLM Provider with tool calling support.
 *
 * Ollama supports tool calling via the /api/chat endpoint when the model
 * supports it (e.g., qwen3-coder, llama3.1, mistral).
 */
class OllamaProvider implements LlmProviderInterface
{
    private string $host;
    private string $model;
    private float $temperature;
    private int $numCtx;

    public function __construct(
        array $config = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->host = rtrim($config['host'] ?? 'http://localhost:11434', '/');
        $this->model = $config['model'] ?? 'qwen3-coder:30b';
        $this->temperature = $config['temperature'] ?? 0.7;
        $this->numCtx = $config['num_ctx'] ?? 32768;
    }

    public function getName(): string
    {
        return 'ollama';
    }

    public function isAvailable(): bool
    {
        $ch = curl_init("{$this->host}/api/tags");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    public function chat(array $messages, array $tools = [], array $options = []): LlmResponse
    {
        $payload = $this->buildPayload($messages, $tools, $options);
        $payload['stream'] = false;

        $response = $this->doRequest($payload);

        if (!$response) {
            return LlmResponse::error('No response from Ollama');
        }

        return $this->parseResponse($response);
    }

    public function chatStream(
        array $messages,
        array $tools = [],
        ?callable $onChunk = null,
        ?callable $onToolCall = null,
        array $options = []
    ): LlmResponse {
        $payload = $this->buildPayload($messages, $tools, $options);
        $payload['stream'] = true;

        $fullContent = '';
        $toolCalls = [];
        $inThinkBlock = false;

        $ch = curl_init("{$this->host}/api/chat");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/x-ndjson',
            ],
            CURLOPT_TIMEOUT => 300,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$fullContent, &$toolCalls, &$inThinkBlock, $onChunk, $onToolCall) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    $json = json_decode($line, true);
                    if (!$json) continue;

                    // Handle tool calls
                    if (isset($json['message']['tool_calls'])) {
                        foreach ($json['message']['tool_calls'] as $tc) {
                            // Arguments may be string (JSON) or array
                            $args = $tc['function']['arguments'] ?? [];
                            if (is_string($args)) {
                                $args = json_decode($args, true) ?? [];
                            }

                            $toolCall = [
                                'id' => $tc['id'] ?? uniqid('call_'),
                                'name' => $tc['function']['name'] ?? '',
                                'arguments' => $args,
                            ];
                            $toolCalls[] = $toolCall;

                            if ($onToolCall) {
                                $onToolCall($toolCall);
                            }
                        }
                    }

                    // Handle content
                    if (isset($json['message']['content'])) {
                        $chunk = $json['message']['content'];

                        // Strip <think> blocks (qwen3 style)
                        $chunk = $this->stripThinkBlocks($chunk, $inThinkBlock);

                        if (!empty($chunk)) {
                            $fullContent .= $chunk;
                            if ($onChunk) {
                                $onChunk($chunk);
                            }
                        }
                    }
                }
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return LlmResponse::error("Curl error: {$error}");
        }

        if (!empty($toolCalls)) {
            return LlmResponse::withToolCalls($toolCalls, $fullContent);
        }

        return LlmResponse::text($fullContent);
    }

    /**
     * Build the request payload
     */
    private function buildPayload(array $messages, array $tools, array $options): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'options' => [
                'temperature' => $options['temperature'] ?? $this->temperature,
                'num_ctx' => $options['num_ctx'] ?? $this->numCtx,
            ],
        ];

        // Add tools if provided
        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        return $payload;
    }

    /**
     * Make non-streaming request
     */
    private function doRequest(array $payload): ?array
    {
        $ch = curl_init("{$this->host}/api/chat");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("Ollama request failed", ['error' => $error]);
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Parse non-streaming response
     */
    private function parseResponse(array $response): LlmResponse
    {
        if (isset($response['error'])) {
            return LlmResponse::error($response['error']);
        }

        $message = $response['message'] ?? [];
        $content = $message['content'] ?? '';
        $toolCalls = [];

        // Parse tool calls
        if (isset($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                // Arguments may be string (JSON) or array
                $args = $tc['function']['arguments'] ?? [];
                if (is_string($args)) {
                    $args = json_decode($args, true) ?? [];
                }

                $toolCalls[] = [
                    'id' => $tc['id'] ?? uniqid('call_'),
                    'name' => $tc['function']['name'] ?? '',
                    'arguments' => $args,
                ];
            }
        }

        // Strip think blocks from content
        $inThinkBlock = false;
        $content = $this->stripThinkBlocks($content, $inThinkBlock);

        if (!empty($toolCalls)) {
            return LlmResponse::withToolCalls($toolCalls, $content, $response);
        }

        return LlmResponse::text($content, [
            'prompt_tokens' => $response['prompt_eval_count'] ?? 0,
            'completion_tokens' => $response['eval_count'] ?? 0,
        ], $response);
    }

    /**
     * Strip <think>...</think> blocks from content
     */
    private function stripThinkBlocks(string $chunk, bool &$inThinkBlock): string
    {
        if ($inThinkBlock) {
            $endPos = strpos($chunk, '</think>');
            if ($endPos !== false) {
                $chunk = substr($chunk, $endPos + 8);
                $inThinkBlock = false;
            } else {
                return '';
            }
        }

        $startPos = strpos($chunk, '<think>');
        if ($startPos !== false) {
            $endPos = strpos($chunk, '</think>', $startPos);
            if ($endPos !== false) {
                $chunk = substr($chunk, 0, $startPos) . substr($chunk, $endPos + 8);
            } else {
                $chunk = substr($chunk, 0, $startPos);
                $inThinkBlock = true;
            }
        }

        return $chunk;
    }
}
