<?php

declare(strict_types=1);

namespace Fastmcphp\Tools;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Fastmcphp\Attributes\Tool as ToolAttribute;
use Fastmcphp\Server\Context;
use Fastmcphp\Utilities\TypeReflector;

/**
 * A tool implementation that wraps a PHP callable.
 */
final class FunctionTool implements Tool
{
    private readonly Closure $callable;
    private readonly ReflectionFunction|ReflectionMethod $reflection;
    private readonly string $name;
    private readonly string $description;
    /** @var array<string, mixed> */
    private readonly array $inputSchema;
    /** @var array<string> */
    private readonly array $tags;
    private readonly ?float $timeout;
    private bool $needsContext = false;
    private ?string $contextParamName = null;

    /**
     * @param callable $callable The function to wrap
     * @param string|null $name Tool name (defaults to function name)
     * @param string|null $description Tool description (defaults to docblock)
     * @param array<string>|null $tags Categorization tags
     * @param float|null $timeout Execution timeout in seconds
     */
    public function __construct(
        callable $callable,
        ?string $name = null,
        ?string $description = null,
        ?array $tags = null,
        ?float $timeout = null,
    ) {
        $this->callable = $callable(...);
        $this->reflection = new ReflectionFunction($this->callable);

        // Check for Tool attribute
        $attributes = $this->reflection->getAttributes(ToolAttribute::class);
        $attribute = !empty($attributes) ? $attributes[0]->newInstance() : null;

        // Determine name
        $this->name = $name
            ?? $attribute?->name
            ?? $this->reflection->getName();

        // Determine description
        $this->description = $description
            ?? $attribute?->description
            ?? TypeReflector::getFunctionDescription($this->reflection)
            ?? '';

        // Determine tags
        $this->tags = $tags ?? $attribute?->tags ?? [];

        // Determine timeout
        $this->timeout = $timeout ?? $attribute?->timeout;

        // Generate input schema and detect context parameter
        $this->inputSchema = $this->buildInputSchema();
    }

    /**
     * Create from a callable with optional attribute metadata.
     */
    public static function fromCallable(
        callable $callable,
        ?string $name = null,
        ?string $description = null,
        ?array $tags = null,
        ?float $timeout = null,
    ): self {
        return new self($callable, $name, $description, $tags, $timeout);
    }

    /**
     * Build the input schema and detect context injection.
     *
     * @return array<string, mixed>
     */
    private function buildInputSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->reflection->getParameters() as $param) {
            $type = $param->getType();

            // Check for Context injection
            if ($type instanceof ReflectionNamedType && $type->getName() === Context::class) {
                $this->needsContext = true;
                $this->contextParamName = $param->getName();
                continue;
            }

            $paramName = $param->getName();
            $properties[$paramName] = TypeReflector::parameterToSchema($param);

            if (!$param->isOptional() && !$param->allowsNull()) {
                $required[] = $paramName;
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    /**
     * Get the tags for this tool.
     *
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Get the timeout for this tool.
     */
    public function getTimeout(): ?float
    {
        return $this->timeout;
    }

    /**
     * Check if this tool needs context injection.
     */
    public function needsContext(): bool
    {
        return $this->needsContext;
    }

    public function execute(array $arguments, ?Context $context = null): ToolResult
    {
        try {
            // Inject context if needed
            if ($this->needsContext && $this->contextParamName !== null) {
                $arguments[$this->contextParamName] = $context ?? new Context();
            }

            // Build ordered arguments
            $orderedArgs = [];
            foreach ($this->reflection->getParameters() as $param) {
                $paramName = $param->getName();

                if (array_key_exists($paramName, $arguments)) {
                    $orderedArgs[] = $arguments[$paramName];
                } elseif ($param->isDefaultValueAvailable()) {
                    $orderedArgs[] = $param->getDefaultValue();
                } elseif ($param->allowsNull()) {
                    $orderedArgs[] = null;
                } else {
                    throw new \InvalidArgumentException("Missing required argument: {$paramName}");
                }
            }

            $result = ($this->callable)(...$orderedArgs);

            return ToolResult::fromValue($result);
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    public function toMcpTool(): array
    {
        $tool = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];

        return $tool;
    }
}
