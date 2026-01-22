<?php

declare(strict_types=1);

namespace Fastmcphp\Resources;

use Closure;
use ReflectionFunction;
use Fastmcphp\Utilities\TypeReflector;
use Fastmcphp\Utilities\UriTemplate;

/**
 * A resource template implementation that wraps a PHP callable.
 */
final class FunctionResourceTemplate implements ResourceTemplate
{
    private readonly Closure $callable;
    private readonly ReflectionFunction $reflection;
    private readonly string $name;
    private readonly string $description;

    public function __construct(
        private readonly string $uriTemplate,
        callable $callable,
        ?string $name = null,
        ?string $description = null,
        private readonly ?string $mimeType = null,
    ) {
        $this->callable = $callable(...);
        $this->reflection = new ReflectionFunction($this->callable);

        $this->name = $name ?? $this->reflection->getName();
        $this->description = $description
            ?? TypeReflector::getFunctionDescription($this->reflection)
            ?? '';
    }

    public function getUriTemplate(): string
    {
        return $this->uriTemplate;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function matchUri(string $uri): ?array
    {
        return UriTemplate::match($uri, $this->uriTemplate);
    }

    public function read(array $params): ResourceResult
    {
        // Build arguments in the correct order for the function
        $args = [];
        foreach ($this->reflection->getParameters() as $param) {
            $paramName = $param->getName();

            if (array_key_exists($paramName, $params)) {
                // Coerce string to the expected type
                $args[] = $this->coerceValue($params[$paramName], $param);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                throw new \InvalidArgumentException("Missing required parameter: {$paramName}");
            }
        }

        $value = ($this->callable)(...$args);

        // Generate the actual URI for this resource
        $uri = UriTemplate::expand($this->uriTemplate, $params);

        return ResourceResult::fromValue($uri, $value, $this->mimeType);
    }

    /**
     * Coerce a string value to the expected type.
     */
    private function coerceValue(string $value, \ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    public function toMcpResourceTemplate(): array
    {
        $template = [
            'uriTemplate' => $this->uriTemplate,
            'name' => $this->name,
        ];

        if ($this->description !== '') {
            $template['description'] = $this->description;
        }

        if ($this->mimeType !== null) {
            $template['mimeType'] = $this->mimeType;
        }

        return $template;
    }
}
