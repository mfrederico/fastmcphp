<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Middleware;

/**
 * Interface for MCP middleware.
 *
 * Middleware can intercept and modify requests/responses at various points
 * in the MCP request lifecycle.
 *
 * Example implementation:
 *
 *   class LoggingMiddleware implements MiddlewareInterface
 *   {
 *       public function onCallTool(MiddlewareContext $ctx, callable $next): mixed
 *       {
 *           $this->logger->info("Calling tool: {$ctx->getToolName()}");
 *           $result = $next($ctx);
 *           $this->logger->info("Tool completed");
 *           return $result;
 *       }
 *   }
 */
interface MiddlewareInterface
{
    /**
     * Called when a client initializes the connection.
     *
     * @param MiddlewareContext $context The middleware context
     * @param callable $next Call to proceed to the next middleware/handler
     * @return mixed The result from the handler chain
     */
    public function onInitialize(MiddlewareContext $context, callable $next): mixed;

    /**
     * Called when a tool is invoked.
     *
     * @param MiddlewareContext $context The middleware context
     * @param callable $next Call to proceed to the next middleware/handler
     * @return mixed The result from the handler chain
     */
    public function onCallTool(MiddlewareContext $context, callable $next): mixed;

    /**
     * Called when listing tools.
     *
     * @param MiddlewareContext $context The middleware context
     * @param callable $next Call to proceed to the next middleware/handler
     * @return mixed The result from the handler chain
     */
    public function onListTools(MiddlewareContext $context, callable $next): mixed;

    /**
     * Called when reading a resource.
     *
     * @param MiddlewareContext $context The middleware context
     * @param callable $next Call to proceed to the next middleware/handler
     * @return mixed The result from the handler chain
     */
    public function onReadResource(MiddlewareContext $context, callable $next): mixed;

    /**
     * Called when listing resources.
     *
     * @param MiddlewareContext $context The middleware context
     * @param callable $next Call to proceed to the next middleware/handler
     * @return mixed The result from the handler chain
     */
    public function onListResources(MiddlewareContext $context, callable $next): mixed;

    /**
     * Called when getting a prompt.
     *
     * @param MiddlewareContext $context The middleware context
     * @param callable $next Call to proceed to the next middleware/handler
     * @return mixed The result from the handler chain
     */
    public function onGetPrompt(MiddlewareContext $context, callable $next): mixed;

    /**
     * Called when listing prompts.
     *
     * @param MiddlewareContext $context The middleware context
     * @param callable $next Call to proceed to the next middleware/handler
     * @return mixed The result from the handler chain
     */
    public function onListPrompts(MiddlewareContext $context, callable $next): mixed;

    /**
     * Called for any request (catch-all).
     *
     * @param MiddlewareContext $context The middleware context
     * @param callable $next Call to proceed to the next middleware/handler
     * @return mixed The result from the handler chain
     */
    public function onRequest(MiddlewareContext $context, callable $next): mixed;
}
