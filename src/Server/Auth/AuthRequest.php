<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Auth;

/**
 * Encapsulates the request data needed for authentication.
 *
 * This abstraction allows auth providers to work across different
 * transports (HTTP, stdio, SSE) without knowing transport details.
 */
class AuthRequest
{
    /**
     * @param array<string, string> $headers HTTP headers (lowercase keys)
     * @param array<string, string> $query Query parameters
     * @param string|null $body Raw request body
     * @param array<string, mixed> $extra Additional transport-specific data
     */
    public function __construct(
        public readonly array $headers = [],
        public readonly array $query = [],
        public readonly ?string $body = null,
        public readonly array $extra = [],
    ) {}

    /**
     * Get a header value (case-insensitive).
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? $default;
    }

    /**
     * Get the Authorization header.
     */
    public function getAuthorization(): ?string
    {
        return $this->getHeader('authorization');
    }

    /**
     * Extract Bearer token from Authorization header.
     */
    public function getBearerToken(): ?string
    {
        $auth = $this->getAuthorization();
        if ($auth === null) {
            return null;
        }

        // Case-insensitive match for "Bearer "
        if (stripos($auth, 'bearer ') === 0) {
            return substr($auth, 7);
        }

        return null;
    }

    /**
     * Get API token from X-API-TOKEN header.
     */
    public function getApiToken(): ?string
    {
        return $this->getHeader('x-api-token');
    }

    /**
     * Get API key from query parameter.
     */
    public function getApiKeyFromQuery(string $param = 'key'): ?string
    {
        return $this->query[$param] ?? null;
    }

    /**
     * Get any available token (checks multiple sources).
     *
     * Priority: X-API-TOKEN header > Authorization Bearer > ?key= query
     */
    public function getToken(): ?string
    {
        return $this->getApiToken()
            ?? $this->getBearerToken()
            ?? $this->getApiKeyFromQuery();
    }

    /**
     * Get a query parameter.
     */
    public function getQuery(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Alias for getQuery().
     */
    public function getQueryParam(string $key, ?string $default = null): ?string
    {
        return $this->getQuery($key, $default);
    }

    /**
     * Get API key from query parameter (alias for getApiKeyFromQuery).
     */
    public function getQueryToken(string $param = 'key'): ?string
    {
        return $this->getApiKeyFromQuery($param);
    }

    /**
     * Get extra data.
     */
    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    /**
     * Create from HTTP request arrays.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    public static function fromHttp(array $headers, array $query = [], ?string $body = null): self
    {
        // Normalize header keys to lowercase
        $normalizedHeaders = [];
        foreach ($headers as $key => $value) {
            $normalizedHeaders[strtolower($key)] = $value;
        }

        return new self(
            headers: $normalizedHeaders,
            query: $query,
            body: $body,
        );
    }

    /**
     * Create from Swoole HTTP request.
     */
    public static function fromSwoole(\Swoole\Http\Request $request): self
    {
        return new self(
            headers: array_change_key_case($request->header ?? [], CASE_LOWER),
            query: $request->get ?? [],
            body: $request->getContent() ?: null,
            extra: [
                'fd' => $request->fd,
                'server' => $request->server ?? [],
            ],
        );
    }

    /**
     * Create an empty auth request (for stdio transport).
     */
    public static function empty(): self
    {
        return new self();
    }
}
