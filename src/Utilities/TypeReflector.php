<?php

declare(strict_types=1);

namespace Fastmcphp\Utilities;

use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use ReflectionIntersectionType;
use Fastmcphp\Server\Context;

/**
 * Utility for converting PHP type hints to JSON schemas.
 */
final class TypeReflector
{
    /**
     * Generate a JSON schema from a function's parameters.
     *
     * @return array<string, mixed>
     */
    public static function getInputSchema(ReflectionFunction|ReflectionMethod $reflection): array
    {
        $properties = [];
        $required = [];

        foreach ($reflection->getParameters() as $param) {
            // Skip context injection parameters
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === Context::class) {
                continue;
            }

            $name = $param->getName();
            $properties[$name] = self::parameterToSchema($param);

            if (!$param->isOptional() && !$param->allowsNull()) {
                $required[] = $name;
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

    /**
     * Convert a parameter to a JSON schema.
     *
     * @return array<string, mixed>
     */
    public static function parameterToSchema(ReflectionParameter $param): array
    {
        $type = $param->getType();
        $schema = self::typeToSchema($type);

        // Add description from docblock if available
        $description = self::getParameterDescription($param);
        if ($description !== null) {
            $schema['description'] = $description;
        }

        // Add default value if available
        if ($param->isDefaultValueAvailable()) {
            $default = $param->getDefaultValue();
            if ($default !== null) {
                $schema['default'] = $default;
            }
        }

        return $schema;
    }

    /**
     * Convert a reflection type to a JSON schema.
     *
     * @return array<string, mixed>
     */
    public static function typeToSchema(?\ReflectionType $type): array
    {
        if ($type === null) {
            return ['type' => 'string'];
        }

        if ($type instanceof ReflectionUnionType) {
            return self::unionTypeToSchema($type);
        }

        if ($type instanceof ReflectionIntersectionType) {
            // Intersection types are complex; treat as object
            return ['type' => 'object'];
        }

        if ($type instanceof ReflectionNamedType) {
            return self::namedTypeToSchema($type);
        }

        return ['type' => 'string'];
    }

    /**
     * Convert a named type to a JSON schema.
     *
     * @return array<string, mixed>
     */
    private static function namedTypeToSchema(ReflectionNamedType $type): array
    {
        $name = $type->getName();

        $schema = match ($name) {
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double' => ['type' => 'number'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            'array' => ['type' => 'array'],
            'object', 'stdClass' => ['type' => 'object'],
            'mixed' => [],
            'null' => ['type' => 'null'],
            default => self::classTypeToSchema($name),
        };

        // Handle nullable types
        if ($type->allowsNull() && $name !== 'null' && $name !== 'mixed') {
            if (isset($schema['type'])) {
                $schema['type'] = [$schema['type'], 'null'];
            }
        }

        return $schema;
    }

    /**
     * Convert a union type to a JSON schema.
     *
     * @return array<string, mixed>
     */
    private static function unionTypeToSchema(ReflectionUnionType $type): array
    {
        $types = [];
        $hasNull = false;

        foreach ($type->getTypes() as $subType) {
            if ($subType instanceof ReflectionNamedType) {
                if ($subType->getName() === 'null') {
                    $hasNull = true;
                    continue;
                }
                $subSchema = self::namedTypeToSchema($subType);
                if (isset($subSchema['type'])) {
                    $types[] = $subSchema['type'];
                }
            }
        }

        if ($hasNull) {
            $types[] = 'null';
        }

        $types = array_unique($types);

        if (count($types) === 1) {
            return ['type' => $types[0]];
        }

        return ['type' => $types];
    }

    /**
     * Convert a class type to a JSON schema.
     *
     * @return array<string, mixed>
     */
    private static function classTypeToSchema(string $className): array
    {
        // Handle common types
        if ($className === \DateTime::class || $className === \DateTimeImmutable::class) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        // Check if it's an enum
        if (enum_exists($className)) {
            $reflection = new \ReflectionEnum($className);
            $cases = [];
            foreach ($reflection->getCases() as $case) {
                if ($case instanceof \ReflectionEnumBackedCase) {
                    $cases[] = $case->getBackingValue();
                } else {
                    $cases[] = $case->getName();
                }
            }
            return ['enum' => $cases];
        }

        // Default to object
        return ['type' => 'object'];
    }

    /**
     * Get parameter description from docblock.
     */
    private static function getParameterDescription(ReflectionParameter $param): ?string
    {
        $function = $param->getDeclaringFunction();
        $docComment = $function->getDocComment();

        if ($docComment === false) {
            return null;
        }

        $paramName = $param->getName();
        $pattern = '/@param\s+\S+\s+\$' . preg_quote($paramName, '/') . '\s+(.+?)(?:\n|\*\/)/';

        if (preg_match($pattern, $docComment, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Get function description from docblock.
     */
    public static function getFunctionDescription(ReflectionFunction|ReflectionMethod $reflection): ?string
    {
        $docComment = $reflection->getDocComment();

        if ($docComment === false) {
            return null;
        }

        // Remove /** and */ and leading asterisks
        $lines = explode("\n", $docComment);
        $description = [];

        foreach ($lines as $line) {
            $line = preg_replace('/^\s*\/?\*+\/?/', '', $line);
            $line = trim($line);

            // Stop at tags
            if (str_starts_with($line, '@')) {
                break;
            }

            if ($line !== '') {
                $description[] = $line;
            }
        }

        return !empty($description) ? implode(' ', $description) : null;
    }
}
