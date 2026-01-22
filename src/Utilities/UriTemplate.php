<?php

declare(strict_types=1);

namespace Fastmcphp\Utilities;

/**
 * RFC 6570 URI Template matching utilities.
 */
final class UriTemplate
{
    /**
     * Match a URI against a template pattern.
     *
     * @param string $uri The URI to match
     * @param string $template The URI template (e.g., "resource://{city}/weather")
     * @return array<string, string>|null Extracted parameters or null if no match
     */
    public static function match(string $uri, string $template): ?array
    {
        // Split into path and query components
        $uriParts = parse_url($uri);
        $templateParts = parse_url($template);

        $uriPath = ($uriParts['scheme'] ?? '') . '://' . ($uriParts['host'] ?? '') . ($uriParts['path'] ?? '');
        $templatePath = ($templateParts['scheme'] ?? '') . '://' . ($templateParts['host'] ?? '') . ($templateParts['path'] ?? '');

        // Build regex pattern from template
        $pattern = self::buildPattern($templatePath);

        if (!preg_match($pattern, $uriPath, $matches)) {
            return null;
        }

        // Extract named parameters
        $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

        // URL-decode parameters
        foreach ($params as $key => $value) {
            $params[$key] = urldecode($value);
        }

        // Handle query parameters if present
        if (isset($templateParts['query']) && isset($uriParts['query'])) {
            parse_str($uriParts['query'], $queryParams);
            $params = array_merge($params, $queryParams);
        }

        return $params;
    }

    /**
     * Build a regex pattern from a URI template.
     */
    private static function buildPattern(string $template): string
    {
        // Remove query string syntax from template for path matching
        $template = preg_replace('/\{\?[^}]+\}/', '', $template);

        // Escape special regex characters (except { and })
        $pattern = preg_quote($template, '#');

        // Restore and convert {var*} (wildcard) placeholders
        $pattern = preg_replace(
            '/\\\{(\w+)\\\\\*\\\}/',
            '(?P<$1>.+)',
            $pattern
        );

        // Convert {var} placeholders to named capture groups
        $pattern = preg_replace(
            '/\\\{(\w+)\\\}/',
            '(?P<$1>[^/]+)',
            $pattern
        );

        return '#^' . $pattern . '$#';
    }

    /**
     * Expand a URI template with the given parameters.
     *
     * @param string $template The URI template
     * @param array<string, string|int> $params The parameters to substitute
     * @return string The expanded URI
     */
    public static function expand(string $template, array $params): string
    {
        return preg_replace_callback(
            '/\{(\w+)(\*)?\}/',
            function ($matches) use ($params) {
                $name = $matches[1];
                return isset($params[$name]) ? urlencode((string) $params[$name]) : '';
            },
            $template
        );
    }

    /**
     * Extract parameter names from a URI template.
     *
     * @return array<string>
     */
    public static function getParameterNames(string $template): array
    {
        preg_match_all('/\{(\w+)\*?\}/', $template, $matches);
        return $matches[1];
    }

    /**
     * Check if a string contains URI template placeholders.
     */
    public static function isTemplate(string $uri): bool
    {
        return preg_match('/\{[^}]+\}/', $uri) === 1;
    }
}
