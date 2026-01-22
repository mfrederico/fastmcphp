<?php

declare(strict_types=1);

namespace Fastmcphp\Resources;

use Closure;
use ReflectionFunction;
use Fastmcphp\Utilities\TypeReflector;

/**
 * A resource implementation that wraps a PHP callable.
 */
final class FunctionResource implements Resource
{
    private readonly Closure $callable;
    private readonly string $name;
    private readonly string $description;

    public function __construct(
        private readonly string $uri,
        callable $callable,
        ?string $name = null,
        ?string $description = null,
        private readonly ?string $mimeType = null,
    ) {
        $this->callable = $callable(...);

        $reflection = new ReflectionFunction($this->callable);

        $this->name = $name ?? $reflection->getName();
        $this->description = $description
            ?? TypeReflector::getFunctionDescription($reflection)
            ?? '';
    }

    public function getUri(): string
    {
        return $this->uri;
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

    public function read(): ResourceResult
    {
        $value = ($this->callable)();

        return ResourceResult::fromValue($this->uri, $value, $this->mimeType);
    }

    public function toMcpResource(): array
    {
        $resource = [
            'uri' => $this->uri,
            'name' => $this->name,
        ];

        if ($this->description !== '') {
            $resource['description'] = $this->description;
        }

        if ($this->mimeType !== null) {
            $resource['mimeType'] = $this->mimeType;
        }

        return $resource;
    }
}
