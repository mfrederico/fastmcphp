<?php

declare(strict_types=1);

namespace Fastmcphp\Protocol;

use JsonException;

/**
 * JSON-RPC 2.0 message parser and encoder.
 */
final class JsonRpc
{
    public const VERSION = '2.0';

    /**
     * Parse a JSON-RPC message from a JSON string.
     *
     * @throws JsonRpcException
     */
    public static function parse(string $json): Request|Notification
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonRpcException(
                'Parse error: ' . $e->getMessage(),
                ErrorCodes::PARSE_ERROR
            );
        }

        if (!is_array($data)) {
            throw new JsonRpcException(
                'Invalid JSON-RPC message: expected object',
                ErrorCodes::INVALID_REQUEST
            );
        }

        return self::parseMessage($data);
    }

    /**
     * Parse a JSON-RPC message from an array.
     *
     * @param array<string, mixed> $data
     * @throws JsonRpcException
     */
    public static function parseMessage(array $data): Request|Notification
    {
        // Validate JSON-RPC version
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== self::VERSION) {
            throw new JsonRpcException(
                'Invalid JSON-RPC version',
                ErrorCodes::INVALID_REQUEST
            );
        }

        // Validate method
        if (!isset($data['method']) || !is_string($data['method'])) {
            throw new JsonRpcException(
                'Missing or invalid method',
                ErrorCodes::INVALID_REQUEST
            );
        }

        $method = $data['method'];
        $params = $data['params'] ?? [];

        if (!is_array($params)) {
            throw new JsonRpcException(
                'Params must be an object or array',
                ErrorCodes::INVALID_PARAMS
            );
        }

        // If 'id' is present, it's a request; otherwise, it's a notification
        if (array_key_exists('id', $data)) {
            return new Request(
                id: $data['id'],
                method: $method,
                params: $params,
                meta: $data['_meta'] ?? null
            );
        }

        return new Notification(
            method: $method,
            params: $params,
            meta: $data['_meta'] ?? null
        );
    }

    /**
     * Encode a successful response.
     *
     * @param string|int $id
     * @param mixed $result
     */
    public static function encodeResult(string|int $id, mixed $result, ?array $meta = null): string
    {
        $response = [
            'jsonrpc' => self::VERSION,
            'id' => $id,
            'result' => $result,
        ];

        if ($meta !== null) {
            $response['_meta'] = $meta;
        }

        return json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Encode an error response.
     *
     * @param string|int|null $id
     */
    public static function encodeError(
        string|int|null $id,
        int $code,
        string $message,
        mixed $data = null
    ): string {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        $response = [
            'jsonrpc' => self::VERSION,
            'id' => $id,
            'error' => $error,
        ];

        return json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Encode a notification (server to client, no id).
     *
     * @param array<string, mixed> $params
     */
    public static function encodeNotification(string $method, array $params = [], ?array $meta = null): string
    {
        $notification = [
            'jsonrpc' => self::VERSION,
            'method' => $method,
            'params' => $params,
        ];

        if ($meta !== null) {
            $notification['_meta'] = $meta;
        }

        return json_encode($notification, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
