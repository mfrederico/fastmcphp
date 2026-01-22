<?php

declare(strict_types=1);

namespace Fastmcphp;

use Fastmcphp\Server\Server;
use Fastmcphp\Server\Transport\TransportInterface;
use Fastmcphp\Server\Transport\StdioTransport;
use Fastmcphp\Server\Transport\HttpTransport;
use Fastmcphp\Server\Transport\SseTransport;
use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Auth\AuthorizationContext;
use Fastmcphp\Server\Middleware\MiddlewareInterface;
use Fastmcphp\Tools\FunctionTool;
use Fastmcphp\Tools\Tool;
use Fastmcphp\Resources\FunctionResource;
use Fastmcphp\Resources\Resource;
use Fastmcphp\Resources\FunctionResourceTemplate;
use Fastmcphp\Resources\ResourceTemplate;
use Fastmcphp\Prompts\FunctionPrompt;
use Fastmcphp\Prompts\Prompt;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * FastMCP PHP - Main entry point for creating MCP servers.
 *
 * Usage:
 *   $mcp = new Fastmcphp('My Server');
 *
 *   // Add authentication
 *   $mcp->setAuth(new MyAuthProvider());
 *
 *   // Add middleware
 *   $mcp->addMiddleware(new LoggingMiddleware());
 *
 *   // Register tools with optional per-tool auth
 *   $mcp->tool(
 *       callable: fn(string $text) => $text,
 *       name: 'echo',
 *       auth: fn(AuthorizationContext $ctx) => $ctx->user->hasLevel(100),
 *   );
 *
 *   $mcp->run(transport: 'stdio');
 */
class Fastmcphp
{
    private readonly Server $server;

    public function __construct(
        string $name,
        string $version = '1.0.0',
        ?string $instructions = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->server = new Server(
            name: $name,
            version: $version,
            instructions: $instructions,
            logger: $logger,
        );
    }

    /**
     * Set the authentication provider.
     *
     * @param AuthProviderInterface $provider The auth provider implementation
     * @param bool $required Whether authentication is required (reject unauthenticated requests)
     * @return $this
     */
    public function setAuth(AuthProviderInterface $provider, bool $required = true): self
    {
        $this->server->setAuthProvider($provider, $required);
        return $this;
    }

    /**
     * Add middleware to the request processing chain.
     *
     * Middleware is executed in the order added.
     *
     * @param MiddlewareInterface $middleware The middleware to add
     * @return $this
     */
    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->server->addMiddleware($middleware);
        return $this;
    }

    /**
     * Register a tool from a callable.
     *
     * @param callable $callable The function to wrap as a tool
     * @param string|null $name Tool name (defaults to function name)
     * @param string|null $description Tool description
     * @param array<string>|null $tags Categorization tags
     * @param float|null $timeout Execution timeout in seconds
     * @param callable|null $auth Authorization callback: fn(AuthorizationContext): bool
     * @return $this
     */
    public function tool(
        callable $callable,
        ?string $name = null,
        ?string $description = null,
        ?array $tags = null,
        ?float $timeout = null,
        ?callable $auth = null,
    ): self {
        $tool = new FunctionTool(
            callable: $callable,
            name: $name,
            description: $description,
            tags: $tags,
            timeout: $timeout,
        );

        $this->server->addTool($tool, $auth);

        return $this;
    }

    /**
     * Register a tool instance with optional authorization.
     *
     * @param Tool $tool The tool instance
     * @param callable|null $auth Authorization callback: fn(AuthorizationContext): bool
     * @return $this
     */
    public function addTool(Tool $tool, ?callable $auth = null): self
    {
        $this->server->addTool($tool, $auth);
        return $this;
    }

    /**
     * Register a resource from a callable.
     *
     * @param string $uri Resource URI (can contain {placeholders} for templates)
     * @param callable $callable The function to provide resource content
     * @param string|null $name Resource name
     * @param string|null $description Resource description
     * @param string|null $mimeType MIME type of the content
     * @param callable|null $auth Authorization callback: fn(AuthorizationContext): bool
     * @return $this
     */
    public function resource(
        string $uri,
        callable $callable,
        ?string $name = null,
        ?string $description = null,
        ?string $mimeType = null,
        ?callable $auth = null,
    ): self {
        // Check if URI contains placeholders -> template
        if (preg_match('/\{[^}]+\}/', $uri)) {
            $template = new FunctionResourceTemplate(
                uriTemplate: $uri,
                callable: $callable,
                name: $name,
                description: $description,
                mimeType: $mimeType,
            );
            $this->server->addResourceTemplate($template, $auth);
        } else {
            $resource = new FunctionResource(
                uri: $uri,
                callable: $callable,
                name: $name,
                description: $description,
                mimeType: $mimeType,
            );
            $this->server->addResource($resource, $auth);
        }

        return $this;
    }

    /**
     * Register a resource instance with optional authorization.
     *
     * @param Resource $resource The resource instance
     * @param callable|null $auth Authorization callback
     * @return $this
     */
    public function addResource(Resource $resource, ?callable $auth = null): self
    {
        $this->server->addResource($resource, $auth);
        return $this;
    }

    /**
     * Register a resource template instance with optional authorization.
     *
     * @param ResourceTemplate $template The template instance
     * @param callable|null $auth Authorization callback
     * @return $this
     */
    public function addResourceTemplate(ResourceTemplate $template, ?callable $auth = null): self
    {
        $this->server->addResourceTemplate($template, $auth);
        return $this;
    }

    /**
     * Register a prompt from a callable.
     *
     * @param callable $callable The function that returns prompt messages
     * @param string|null $name Prompt name
     * @param string|null $description Prompt description
     * @param callable|null $auth Authorization callback: fn(AuthorizationContext): bool
     * @return $this
     */
    public function prompt(
        callable $callable,
        ?string $name = null,
        ?string $description = null,
        ?callable $auth = null,
    ): self {
        $prompt = new FunctionPrompt(
            callable: $callable,
            name: $name,
            description: $description,
        );

        $this->server->addPrompt($prompt, $auth);

        return $this;
    }

    /**
     * Register a prompt instance with optional authorization.
     *
     * @param Prompt $prompt The prompt instance
     * @param callable|null $auth Authorization callback
     * @return $this
     */
    public function addPrompt(Prompt $prompt, ?callable $auth = null): self
    {
        $this->server->addPrompt($prompt, $auth);
        return $this;
    }

    /**
     * Run the server with the specified transport.
     *
     * @param string|TransportInterface $transport Transport type or instance
     * @param string $host Host to bind to (for HTTP/SSE)
     * @param int $port Port to bind to (for HTTP/SSE)
     */
    public function run(
        string|TransportInterface $transport = 'stdio',
        string $host = '0.0.0.0',
        int $port = 8080,
    ): void {
        if (is_string($transport)) {
            $transport = match ($transport) {
                'stdio' => new StdioTransport(),
                'http' => new HttpTransport($host, $port),
                'sse' => new SseTransport($host, $port),
                default => throw new \InvalidArgumentException("Unknown transport: {$transport}"),
            };
        }

        $this->logger->info("Starting MCP server with " . get_class($transport));

        $transport->run($this->server);
    }

    /**
     * Get the underlying server instance.
     */
    public function getServer(): Server
    {
        return $this->server;
    }
}
