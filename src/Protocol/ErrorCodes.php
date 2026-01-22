<?php

declare(strict_types=1);

namespace Fastmcphp\Protocol;

/**
 * JSON-RPC 2.0 and MCP standard error codes.
 */
final class ErrorCodes
{
    // JSON-RPC 2.0 standard errors
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    // MCP-specific errors (-32000 to -32099 reserved for implementation)
    public const SERVER_ERROR = -32000;
    public const NOT_FOUND = -32001;
    public const UNAUTHORIZED = -32002;
    public const FORBIDDEN = -32003;
    public const TIMEOUT = -32004;
    public const VALIDATION_ERROR = -32005;

    public static function getMessage(int $code): string
    {
        return match ($code) {
            self::PARSE_ERROR => 'Parse error',
            self::INVALID_REQUEST => 'Invalid Request',
            self::METHOD_NOT_FOUND => 'Method not found',
            self::INVALID_PARAMS => 'Invalid params',
            self::INTERNAL_ERROR => 'Internal error',
            self::SERVER_ERROR => 'Server error',
            self::NOT_FOUND => 'Not found',
            self::UNAUTHORIZED => 'Unauthorized',
            self::FORBIDDEN => 'Forbidden',
            self::TIMEOUT => 'Request timeout',
            self::VALIDATION_ERROR => 'Validation error',
            default => 'Unknown error',
        };
    }
}
