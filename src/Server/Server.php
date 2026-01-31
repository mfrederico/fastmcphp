<?php

declare(strict_types=1);

namespace Fastmcphp\Server;

use DateTimeImmutable;
use Fastmcphp\Protocol\ErrorCodes;
use Fastmcphp\Protocol\JsonRpc;
use Fastmcphp\Protocol\JsonRpcException;
use Fastmcphp\Protocol\Notification;
use Fastmcphp\Protocol\Request;
use Fastmcphp\Server\Auth\AuthenticatedUser;
use Fastmcphp\Server\Auth\AuthorizationContext;
use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Middleware\MiddlewareChain;
use Fastmcphp\Server\Middleware\MiddlewareContext;
use Fastmcphp\Server\Middleware\MiddlewareInterface;
use Fastmcphp\Tools\Tool;
use Fastmcphp\Tools\FunctionTool;
use Fastmcphp\Resources\Resource;
use Fastmcphp\Resources\ResourceTemplate;
use Fastmcphp\Prompts\Prompt;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MCP Server implementation with middleware and authentication support.
 */
class Server
{
    private const PROTOCOL_VERSION = '2024-11-05';

    private bool $initialized = false;
    private MiddlewareChain $middlewareChain;
    private ?AuthProviderInterface $authProvider = null;
    private bool $authRequired = false;

    /** @var array<string, Tool> */
    private array $tools = [];

    /** @var array<string, Resource> */
    private array $resources = [];

    /** @var array<string, ResourceTemplate> */
    private array $resourceTemplates = [];

    /** @var array<string, Prompt> */
    private array $prompts = [];

    /** @var array<string, callable> Per-tool authorization callbacks */
    private array $toolAuth = [];

    /** @var array<string, callable> Per-resource authorization callbacks */
    private array $resourceAuth = [];

    /** @var array<string, callable> Per-prompt authorization callbacks */
    private array $promptAuth = [];

    public function __construct(
        private readonly string $name,
        private readonly string $version = '1.0.0',
        private readonly ?string $instructions = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->middlewareChain = new MiddlewareChain();
    }

    /**
     * Set the authentication provider.
     */
    public function setAuthProvider(AuthProviderInterface $provider, bool $required = true): void
    {
        $this->authProvider = $provider;
        $this->authRequired = $required;
    }

    /**
     * Add middleware to the chain.
     */
    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        $this->middlewareChain->add($middleware);
    }

    /**
     * Register a tool with optional authorization.
     *
     * @param callable|null $auth Authorization callback: fn(AuthorizationContext): bool
     */
    public function addTool(Tool $tool, ?callable $auth = null): void
    {
        $this->tools[$tool->getName()] = $tool;

        if ($auth !== null) {
            $this->toolAuth[$tool->getName()] = $auth;
        }
    }

    /**
     * Register a resource with optional authorization.
     */
    public function addResource(Resource $resource, ?callable $auth = null): void
    {
        $this->resources[$resource->getUri()] = $resource;

        if ($auth !== null) {
            $this->resourceAuth[$resource->getUri()] = $auth;
        }
    }

    /**
     * Register a resource template with optional authorization.
     */
    public function addResourceTemplate(ResourceTemplate $template, ?callable $auth = null): void
    {
        $this->resourceTemplates[$template->getUriTemplate()] = $template;

        if ($auth !== null) {
            $this->resourceAuth[$template->getUriTemplate()] = $auth;
        }
    }

    /**
     * Register a prompt with optional authorization.
     */
    public function addPrompt(Prompt $prompt, ?callable $auth = null): void
    {
        $this->prompts[$prompt->getName()] = $prompt;

        if ($auth !== null) {
            $this->promptAuth[$prompt->getName()] = $auth;
        }
    }

    /**
     * Get all registered tools.
     *
     * @return array<string, Tool>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * Get all registered resources.
     *
     * @return array<string, Resource>
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    /**
     * Get all registered resource templates.
     *
     * @return array<string, ResourceTemplate>
     */
    public function getResourceTemplates(): array
    {
        return $this->resourceTemplates;
    }

    /**
     * Get all registered prompts.
     *
     * @return array<string, Prompt>
     */
    public function getPrompts(): array
    {
        return $this->prompts;
    }

    /**
     * Get the server name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Handle a JSON-RPC message and return the response.
     *
     * @param AuthRequest|null $authRequest Optional auth request for HTTP transports
     */
    public function handle(Request|Notification $message, ?AuthRequest $authRequest = null): ?string
    {
        if ($message instanceof Notification) {
            $this->handleNotification($message);
            return null;
        }

        try {
            $result = $this->handleRequestWithMiddleware($message, $authRequest);
            return JsonRpc::encodeResult($message->id, $result);
        } catch (JsonRpcException $e) {
            return JsonRpc::encodeError(
                $message->id,
                $e->getCode(),
                $e->getMessage(),
                $e->data
            );
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled error: ' . $e->getMessage(), ['exception' => $e]);
            return JsonRpc::encodeError(
                $message->id,
                ErrorCodes::INTERNAL_ERROR,
                'Internal error: ' . $e->getMessage()
            );
        }
    }

    /**
     * Handle a request through the middleware chain.
     *
     * @return array<string, mixed>
     * @throws JsonRpcException
     */
    private function handleRequestWithMiddleware(Request $request, ?AuthRequest $authRequest): array
    {
        // Authenticate if provider is configured
        $user = null;
        $workspace = null;

        if ($this->authProvider !== null && !$this->isPublicMethod($request->method)) {
            $authResult = $this->authProvider->authenticate($authRequest ?? AuthRequest::empty());

            if ($authResult->isFailed()) {
                throw new JsonRpcException(
                    $authResult->error ?? 'Authentication failed',
                    ErrorCodes::UNAUTHORIZED
                );
            }

            if ($authResult->isUnauthenticated() && $this->authRequired) {
                throw new JsonRpcException(
                    'Authentication required',
                    ErrorCodes::UNAUTHORIZED
                );
            }

            if ($authResult->isSuccess()) {
                $user = $authResult->getUser();
                $workspace = $authResult->workspace;
            }
        }

        // Check initialization state - allow public and discovery methods before init
        if (!$this->initialized && !$this->isPreInitMethod($request->method)) {
            throw new JsonRpcException(
                'Server not initialized. Call initialize first.',
                ErrorCodes::INVALID_REQUEST
            );
        }

        // Create middleware context
        $context = new MiddlewareContext(
            message: $request,
            method: $request->method,
            timestamp: new DateTimeImmutable(),
            user: $user,
            workspace: $workspace,
        );

        // Store auth request for middleware access
        if ($authRequest !== null) {
            $context->setAttribute('authRequest', $authRequest);
        }

        // Determine which middleware hook to call
        $hookMethod = MiddlewareChain::getHookMethod($request->method);

        // Run through middleware chain
        return $this->middlewareChain->run(
            $context,
            $hookMethod,
            fn(MiddlewareContext $ctx) => $this->handleRequest($request, $ctx)
        );
    }

    /**
     * Check if a method is public (no auth required).
     */
    private function isPublicMethod(string $method): bool
    {
        return in_array($method, ['initialize', 'initialized', 'ping'], true);
    }

    /**
     * Check if a method can be called before initialization.
     * This includes public methods and discovery methods (list capabilities).
     */
    private function isPreInitMethod(string $method): bool
    {
        return $this->isPublicMethod($method) || in_array($method, [
            'tools/list',
            'resources/list',
            'resources/templates/list',
            'prompts/list',
        ], true);
    }

    /**
     * Handle a request and return the result.
     *
     * @return array<string, mixed>
     * @throws JsonRpcException
     */
    private function handleRequest(Request $request, MiddlewareContext $ctx): array
    {
        return match ($request->method) {
            'initialize' => $this->handleInitialize($request),
            'initialized' => [],
            'ping' => ['pong' => true],
            'tools/list' => $this->handleListTools($request, $ctx),
            'tools/call' => $this->handleCallTool($request, $ctx),
            'resources/list' => $this->handleListResources($request, $ctx),
            'resources/read' => $this->handleReadResource($request, $ctx),
            'resources/templates/list' => $this->handleListResourceTemplates($request, $ctx),
            'prompts/list' => $this->handleListPrompts($request, $ctx),
            'prompts/get' => $this->handleGetPrompt($request, $ctx),
            default => throw new JsonRpcException(
                "Method not found: {$request->method}",
                ErrorCodes::METHOD_NOT_FOUND
            ),
        };
    }

    /**
     * Handle a notification (no response).
     */
    private function handleNotification(Notification $notification): void
    {
        match ($notification->method) {
            'notifications/cancelled' => $this->handleCancelled($notification),
            'notifications/progress' => $this->handleProgress($notification),
            default => $this->logger->debug("Unknown notification: {$notification->method}"),
        };
    }

    /**
     * Handle initialize request.
     *
     * @return array<string, mixed>
     */
    private function handleInitialize(Request $request): array
    {
        $this->initialized = true;

        $capabilities = [
            'tools' => !empty($this->tools) ? new \stdClass() : null,
            'resources' => !empty($this->resources) || !empty($this->resourceTemplates) ? new \stdClass() : null,
            'prompts' => !empty($this->prompts) ? new \stdClass() : null,
        ];

        $capabilities = array_filter($capabilities, fn($v) => $v !== null);

        $result = [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => (object) $capabilities,
            'serverInfo' => (object) [
                'name' => $this->name,
                'version' => $this->version,
            ],
        ];

        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }

        return $result;
    }

    /**
     * Handle tools/list request.
     *
     * @return array<string, mixed>
     */
    private function handleListTools(Request $request, MiddlewareContext $ctx): array
    {
        $tools = [];

        foreach ($this->tools as $tool) {
            // Filter by authorization if user is authenticated
            if ($ctx->user !== null && isset($this->toolAuth[$tool->getName()])) {
                $authCtx = AuthorizationContext::forTool($ctx->user, $tool->getName(), [], $ctx->workspace);
                if (!($this->toolAuth[$tool->getName()])($authCtx)) {
                    continue; // Skip unauthorized tools
                }
            }

            $tools[] = $tool->toMcpTool();
        }

        return ['tools' => $tools];
    }

    /**
     * Handle tools/call request.
     *
     * @return array<string, mixed>
     * @throws JsonRpcException
     */
    private function handleCallTool(Request $request, MiddlewareContext $ctx): array
    {
        $name = $request->getParam('name');
        $arguments = $request->getParam('arguments', []);

        if (!is_string($name)) {
            throw new JsonRpcException(
                'Missing or invalid tool name',
                ErrorCodes::INVALID_PARAMS
            );
        }

        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            throw new JsonRpcException(
                "Tool not found: {$name}",
                ErrorCodes::NOT_FOUND
            );
        }

        // Check authorization
        if ($ctx->user !== null && isset($this->toolAuth[$name])) {
            $authCtx = AuthorizationContext::forTool($ctx->user, $name, $arguments, $ctx->workspace);
            if (!($this->toolAuth[$name])($authCtx)) {
                throw new JsonRpcException(
                    "Not authorized to call tool: {$name}",
                    ErrorCodes::FORBIDDEN
                );
            }
        }

        // Also check scope-based authorization
        if ($ctx->user !== null && !$ctx->user->hasScope("tools:{$name}") && !$ctx->user->hasScope("tools:*")) {
            // Only enforce if user has scopes defined (empty scopes = full access for compatibility)
            if (!empty($ctx->user->scopes)) {
                throw new JsonRpcException(
                    "Insufficient scope to call tool: {$name}",
                    ErrorCodes::FORBIDDEN
                );
            }
        }

        // Create context for the request
        $context = new Context(
            requestId: (string) $request->id,
            logger: $this->logger,
        );

        $result = $tool->execute($arguments, $context);

        return $result->toMcpResult();
    }

    /**
     * Handle resources/list request.
     *
     * @return array<string, mixed>
     */
    private function handleListResources(Request $request, MiddlewareContext $ctx): array
    {
        $resources = [];

        foreach ($this->resources as $resource) {
            // Filter by authorization if user is authenticated
            if ($ctx->user !== null && isset($this->resourceAuth[$resource->getUri()])) {
                $authCtx = AuthorizationContext::forResource($ctx->user, $resource->getUri(), $ctx->workspace);
                if (!($this->resourceAuth[$resource->getUri()])($authCtx)) {
                    continue;
                }
            }

            $resources[] = $resource->toMcpResource();
        }

        return ['resources' => $resources];
    }

    /**
     * Handle resources/read request.
     *
     * @return array<string, mixed>
     * @throws JsonRpcException
     */
    private function handleReadResource(Request $request, MiddlewareContext $ctx): array
    {
        $uri = $request->getParam('uri');

        if (!is_string($uri)) {
            throw new JsonRpcException(
                'Missing or invalid resource URI',
                ErrorCodes::INVALID_PARAMS
            );
        }

        // Try exact match first
        $resource = $this->resources[$uri] ?? null;

        if ($resource !== null) {
            // Check authorization
            if ($ctx->user !== null && isset($this->resourceAuth[$uri])) {
                $authCtx = AuthorizationContext::forResource($ctx->user, $uri, $ctx->workspace);
                if (!($this->resourceAuth[$uri])($authCtx)) {
                    throw new JsonRpcException(
                        "Not authorized to read resource: {$uri}",
                        ErrorCodes::FORBIDDEN
                    );
                }
            }

            $result = $resource->read();
            return ['contents' => [$result->toMcpContent()]];
        }

        // Try template matching
        foreach ($this->resourceTemplates as $template) {
            $params = $template->matchUri($uri);
            if ($params !== null) {
                // Check authorization
                if ($ctx->user !== null && isset($this->resourceAuth[$template->getUriTemplate()])) {
                    $authCtx = AuthorizationContext::forResource($ctx->user, $uri, $ctx->workspace);
                    if (!($this->resourceAuth[$template->getUriTemplate()])($authCtx)) {
                        throw new JsonRpcException(
                            "Not authorized to read resource: {$uri}",
                            ErrorCodes::FORBIDDEN
                        );
                    }
                }

                $result = $template->read($params);
                return ['contents' => [$result->toMcpContent()]];
            }
        }

        throw new JsonRpcException(
            "Resource not found: {$uri}",
            ErrorCodes::NOT_FOUND
        );
    }

    /**
     * Handle resources/templates/list request.
     *
     * @return array<string, mixed>
     */
    private function handleListResourceTemplates(Request $request, MiddlewareContext $ctx): array
    {
        $templates = [];

        foreach ($this->resourceTemplates as $template) {
            // Filter by authorization if user is authenticated
            if ($ctx->user !== null && isset($this->resourceAuth[$template->getUriTemplate()])) {
                $authCtx = AuthorizationContext::forResource($ctx->user, $template->getUriTemplate(), $ctx->workspace);
                if (!($this->resourceAuth[$template->getUriTemplate()])($authCtx)) {
                    continue;
                }
            }

            $templates[] = $template->toMcpResourceTemplate();
        }

        return ['resourceTemplates' => $templates];
    }

    /**
     * Handle prompts/list request.
     *
     * @return array<string, mixed>
     */
    private function handleListPrompts(Request $request, MiddlewareContext $ctx): array
    {
        $prompts = [];

        foreach ($this->prompts as $prompt) {
            // Filter by authorization if user is authenticated
            if ($ctx->user !== null && isset($this->promptAuth[$prompt->getName()])) {
                $authCtx = AuthorizationContext::forPrompt($ctx->user, $prompt->getName(), [], $ctx->workspace);
                if (!($this->promptAuth[$prompt->getName()])($authCtx)) {
                    continue;
                }
            }

            $prompts[] = $prompt->toMcpPrompt();
        }

        return ['prompts' => $prompts];
    }

    /**
     * Handle prompts/get request.
     *
     * @return array<string, mixed>
     * @throws JsonRpcException
     */
    private function handleGetPrompt(Request $request, MiddlewareContext $ctx): array
    {
        $name = $request->getParam('name');
        $arguments = $request->getParam('arguments', []);

        if (!is_string($name)) {
            throw new JsonRpcException(
                'Missing or invalid prompt name',
                ErrorCodes::INVALID_PARAMS
            );
        }

        $prompt = $this->prompts[$name] ?? null;
        if ($prompt === null) {
            throw new JsonRpcException(
                "Prompt not found: {$name}",
                ErrorCodes::NOT_FOUND
            );
        }

        // Check authorization
        if ($ctx->user !== null && isset($this->promptAuth[$name])) {
            $authCtx = AuthorizationContext::forPrompt($ctx->user, $name, $arguments, $ctx->workspace);
            if (!($this->promptAuth[$name])($authCtx)) {
                throw new JsonRpcException(
                    "Not authorized to get prompt: {$name}",
                    ErrorCodes::FORBIDDEN
                );
            }
        }

        $result = $prompt->get($arguments);

        return $result->toMcpResult();
    }

    /**
     * Handle cancellation notification.
     */
    private function handleCancelled(Notification $notification): void
    {
        $requestId = $notification->getParam('requestId');
        $this->logger->info("Request cancelled: {$requestId}");
    }

    /**
     * Handle progress notification.
     */
    private function handleProgress(Notification $notification): void
    {
        $progressToken = $notification->getParam('progressToken');
        $progress = $notification->getParam('progress');
        $total = $notification->getParam('total');
        $this->logger->debug("Progress: {$progress}/{$total} for {$progressToken}");
    }
}
