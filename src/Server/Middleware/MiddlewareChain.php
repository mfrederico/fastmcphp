<?php

declare(strict_types=1);

namespace Fastmcphp\Server\Middleware;

/**
 * Manages the middleware chain execution.
 */
class MiddlewareChain
{
    /** @var array<MiddlewareInterface> */
    private array $middleware = [];

    /**
     * Add middleware to the chain.
     *
     * Middleware is executed in the order added (first added = outermost).
     */
    public function add(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Prepend middleware to the chain (runs first).
     */
    public function prepend(MiddlewareInterface $middleware): void
    {
        array_unshift($this->middleware, $middleware);
    }

    /**
     * Run the middleware chain for a specific method.
     *
     * @param MiddlewareContext $context The middleware context
     * @param string $hookMethod The middleware hook to call (e.g., 'onCallTool')
     * @param callable $handler The final handler to call
     * @return mixed The result from the handler chain
     */
    public function run(MiddlewareContext $context, string $hookMethod, callable $handler): mixed
    {
        // Build the chain from innermost to outermost
        $chain = $handler;

        // Wrap with method-specific hooks (in reverse order)
        foreach (array_reverse($this->middleware) as $mw) {
            $chain = fn(MiddlewareContext $ctx) => $mw->$hookMethod($ctx, $chain);
        }

        // Wrap with onRequest hooks (in reverse order)
        foreach (array_reverse($this->middleware) as $mw) {
            $chain = fn(MiddlewareContext $ctx) => $mw->onRequest($ctx, $chain);
        }

        return $chain($context);
    }

    /**
     * Get the middleware hook method for an MCP method.
     */
    public static function getHookMethod(string $mcpMethod): string
    {
        return match ($mcpMethod) {
            'initialize' => 'onInitialize',
            'tools/call' => 'onCallTool',
            'tools/list' => 'onListTools',
            'resources/read' => 'onReadResource',
            'resources/list' => 'onListResources',
            'resources/templates/list' => 'onListResources',
            'prompts/get' => 'onGetPrompt',
            'prompts/list' => 'onListPrompts',
            default => 'onRequest',
        };
    }

    /**
     * Check if any middleware is registered.
     */
    public function isEmpty(): bool
    {
        return empty($this->middleware);
    }

    /**
     * Get the count of registered middleware.
     */
    public function count(): int
    {
        return count($this->middleware);
    }
}
