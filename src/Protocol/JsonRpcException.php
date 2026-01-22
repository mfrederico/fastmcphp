<?php

declare(strict_types=1);

namespace Fastmcphp\Protocol;

use Exception;

/**
 * Exception for JSON-RPC errors.
 */
class JsonRpcException extends Exception
{
    /**
     * @param string $message Error message
     * @param int $code JSON-RPC error code
     * @param mixed $data Additional error data
     */
    public function __construct(
        string $message,
        int $code = ErrorCodes::INTERNAL_ERROR,
        public readonly mixed $data = null,
    ) {
        parent::__construct($message, $code);
    }

    /**
     * Create an error response JSON string.
     */
    public function toJson(?string $id = null): string
    {
        return JsonRpc::encodeError(
            $id,
            $this->getCode(),
            $this->getMessage(),
            $this->data
        );
    }
}
