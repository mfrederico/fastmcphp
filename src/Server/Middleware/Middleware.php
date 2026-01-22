<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Middleware;

/**
 * Base middleware class with default pass-through implementations.
 *
 * Extend this class and override only the methods you need.
 *
 * Example:
 *
 *   class LoggingMiddleware extends Middleware
 *   {
 *       public function onCallTool(MiddlewareContext $ctx, callable $next): mixed
 *       {
 *           echo "Before: {$ctx->getToolName()}\n";
 *           $result = $next($ctx);
 *           echo "After: completed\n";
 *           return $result;
 *       }
 *   }
 */
abstract class Middleware implements MiddlewareInterface
{
    public function onInitialize(MiddlewareContext $context, callable $next): mixed
    {
        return $next($context);
    }

    public function onCallTool(MiddlewareContext $context, callable $next): mixed
    {
        return $next($context);
    }

    public function onListTools(MiddlewareContext $context, callable $next): mixed
    {
        return $next($context);
    }

    public function onReadResource(MiddlewareContext $context, callable $next): mixed
    {
        return $next($context);
    }

    public function onListResources(MiddlewareContext $context, callable $next): mixed
    {
        return $next($context);
    }

    public function onGetPrompt(MiddlewareContext $context, callable $next): mixed
    {
        return $next($context);
    }

    public function onListPrompts(MiddlewareContext $context, callable $next): mixed
    {
        return $next($context);
    }

    public function onRequest(MiddlewareContext $context, callable $next): mixed
    {
        return $next($context);
    }
}
