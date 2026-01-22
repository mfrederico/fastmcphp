<?php

declare(strict_types=1);

namespace Fastmcphp\Prompts;

use Closure;
use ReflectionFunction;
use ReflectionNamedType;
use Fastmcphp\Utilities\TypeReflector;
use Fastmcphp\Server\Context;

/**
 * A prompt implementation that wraps a PHP callable.
 */
final class FunctionPrompt implements Prompt
{
    private readonly Closure $callable;
    private readonly ReflectionFunction $reflection;
    private readonly string $name;
    private readonly string $description;
    /** @var array<array<string, mixed>> */
    private readonly array $arguments;

    public function __construct(
        callable $callable,
        ?string $name = null,
        ?string $description = null,
    ) {
        $this->callable = $callable(...);
        $this->reflection = new ReflectionFunction($this->callable);

        $this->name = $name ?? $this->reflection->getName();
        $this->description = $description
            ?? TypeReflector::getFunctionDescription($this->reflection)
            ?? '';

        $this->arguments = $this->buildArguments();
    }

    /**
     * Build the arguments list from function parameters.
     *
     * @return array<array<string, mixed>>
     */
    private function buildArguments(): array
    {
        $arguments = [];

        foreach ($this->reflection->getParameters() as $param) {
            $type = $param->getType();

            // Skip context injection
            if ($type instanceof ReflectionNamedType && $type->getName() === Context::class) {
                continue;
            }

            $arg = [
                'name' => $param->getName(),
                'required' => !$param->isOptional() && !$param->allowsNull(),
            ];

            // Get description from docblock
            $description = $this->getParameterDescription($param->getName());
            if ($description !== null) {
                $arg['description'] = $description;
            }

            $arguments[] = $arg;
        }

        return $arguments;
    }

    /**
     * Get parameter description from docblock.
     */
    private function getParameterDescription(string $paramName): ?string
    {
        $docComment = $this->reflection->getDocComment();

        if ($docComment === false) {
            return null;
        }

        $pattern = '/@param\s+\S+\s+\$' . preg_quote($paramName, '/') . '\s+(.+?)(?:\n|\*\/)/';

        if (preg_match($pattern, $docComment, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function get(array $arguments): PromptResult
    {
        // Build ordered arguments
        $orderedArgs = [];
        foreach ($this->reflection->getParameters() as $param) {
            $paramName = $param->getName();
            $type = $param->getType();

            // Skip context injection
            if ($type instanceof ReflectionNamedType && $type->getName() === Context::class) {
                continue;
            }

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

        // Handle different return types
        if ($result instanceof PromptResult) {
            return $result;
        }

        if ($result instanceof Message) {
            return new PromptResult([$result]);
        }

        if (is_array($result)) {
            return PromptResult::fromMessages($result);
        }

        if (is_string($result)) {
            return new PromptResult([Message::user($result)]);
        }

        throw new \RuntimeException('Prompt must return Message, PromptResult, array of messages, or string');
    }

    public function toMcpPrompt(): array
    {
        $prompt = [
            'name' => $this->name,
        ];

        if ($this->description !== '') {
            $prompt['description'] = $this->description;
        }

        if (!empty($this->arguments)) {
            $prompt['arguments'] = $this->arguments;
        }

        return $prompt;
    }
}
