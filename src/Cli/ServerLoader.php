<?php

declare(strict_types=1);

namespace Fastmcphp\Cli;

use Fastmcphp\Fastmcphp;

/**
 * Loads MCP servers from various specification formats.
 *
 * Supported formats:
 * - PHP file: server.php (auto-detects $mcp, $server, or $app variable)
 * - Object spec: server.php:varName (specific variable name)
 * - Config file: fastmcphp.json (JSON configuration)
 * - Callable: Class::method or Class->method syntax
 */
class ServerLoader
{
    /**
     * Load an MCP server from a specification.
     *
     * @param string $spec Server specification
     * @return Fastmcphp The loaded server instance
     * @throws \RuntimeException If server cannot be loaded
     */
    public function load(string $spec): Fastmcphp
    {
        // Check for object spec (file.php:varName)
        if (str_contains($spec, ':') && !str_starts_with($spec, 'http')) {
            return $this->loadFromObjectSpec($spec);
        }

        // Check for JSON config
        if (str_ends_with($spec, '.json')) {
            return $this->loadFromConfig($spec);
        }

        // Check for PHP file
        if (str_ends_with($spec, '.php') || file_exists($spec)) {
            return $this->loadFromFile($spec);
        }

        throw new \RuntimeException("Unknown server specification format: {$spec}");
    }

    /**
     * Load from a PHP file with auto-detection.
     */
    private function loadFromFile(string $file): Fastmcphp
    {
        $file = $this->resolveFile($file);

        // Capture variables defined in the file
        $before = get_defined_vars();

        // Include the file in an isolated scope
        $result = (function (string $__file__) {
            return require $__file__;
        })($file);

        // If the file returns a Fastmcphp instance, use it
        if ($result instanceof Fastmcphp) {
            return $result;
        }

        // Try to find a Fastmcphp instance in global scope
        // Common variable names
        $candidates = ['mcp', 'server', 'app', 'fastmcp'];

        foreach ($candidates as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof Fastmcphp) {
                return $GLOBALS[$name];
            }
        }

        // Check if file defined a function that returns the server
        if (function_exists('createServer')) {
            $server = createServer();
            if ($server instanceof Fastmcphp) {
                return $server;
            }
        }

        throw new \RuntimeException(
            "Could not find Fastmcphp instance in {$file}. " .
            "Either return it from the file, or use a variable named: " .
            implode(', ', array_map(fn($n) => "\${$n}", $candidates))
        );
    }

    /**
     * Load from object spec (file.php:varName).
     */
    private function loadFromObjectSpec(string $spec): Fastmcphp
    {
        [$file, $varName] = explode(':', $spec, 2);

        $file = $this->resolveFile($file);

        // Include the file
        require_once $file;

        // Check global variable
        if (isset($GLOBALS[$varName])) {
            if ($GLOBALS[$varName] instanceof Fastmcphp) {
                return $GLOBALS[$varName];
            }
            throw new \RuntimeException("\${$varName} is not a Fastmcphp instance");
        }

        // Check if it's a class constant or static property
        if (str_contains($varName, '::')) {
            [$class, $member] = explode('::', $varName, 2);

            if (defined("{$class}::{$member}")) {
                $value = constant("{$class}::{$member}");
                if ($value instanceof Fastmcphp) {
                    return $value;
                }
            }

            if (property_exists($class, $member)) {
                $value = $class::$$member;
                if ($value instanceof Fastmcphp) {
                    return $value;
                }
            }
        }

        throw new \RuntimeException("Could not find Fastmcphp instance as \${$varName} in {$file}");
    }

    /**
     * Load from JSON config file.
     */
    private function loadFromConfig(string $configFile): Fastmcphp
    {
        $configFile = $this->resolveFile($configFile);

        $content = file_get_contents($configFile);
        if ($content === false) {
            throw new \RuntimeException("Cannot read config file: {$configFile}");
        }

        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in {$configFile}: " . json_last_error_msg());
        }

        return $this->createFromConfig($config, dirname($configFile));
    }

    /**
     * Create server from configuration array.
     *
     * @param array<string, mixed> $config
     */
    private function createFromConfig(array $config, string $baseDir): Fastmcphp
    {
        $name = $config['name'] ?? 'FastMCP PHP Server';
        $mcp = new Fastmcphp($name);

        // Set description
        if (isset($config['description'])) {
            $mcp->setDescription($config['description']);
        }

        // Load server file if specified
        if (isset($config['server'])) {
            $serverFile = $this->resolvePath($config['server'], $baseDir);
            return $this->loadFromFile($serverFile);
        }

        // Register tools from config
        if (isset($config['tools']) && is_array($config['tools'])) {
            foreach ($config['tools'] as $toolConfig) {
                $this->registerToolFromConfig($mcp, $toolConfig, $baseDir);
            }
        }

        // Register resources from config
        if (isset($config['resources']) && is_array($config['resources'])) {
            foreach ($config['resources'] as $resourceConfig) {
                $this->registerResourceFromConfig($mcp, $resourceConfig, $baseDir);
            }
        }

        return $mcp;
    }

    /**
     * Register a tool from configuration.
     *
     * @param array<string, mixed> $config
     */
    private function registerToolFromConfig(Fastmcphp $mcp, array $config, string $baseDir): void
    {
        if (!isset($config['callable'])) {
            throw new \RuntimeException("Tool config requires 'callable' field");
        }

        $callable = $this->resolveCallable($config['callable'], $baseDir);

        $mcp->tool(
            callable: $callable,
            name: $config['name'] ?? null,
            description: $config['description'] ?? null,
        );
    }

    /**
     * Register a resource from configuration.
     *
     * @param array<string, mixed> $config
     */
    private function registerResourceFromConfig(Fastmcphp $mcp, array $config, string $baseDir): void
    {
        if (!isset($config['uri']) || !isset($config['callable'])) {
            throw new \RuntimeException("Resource config requires 'uri' and 'callable' fields");
        }

        $callable = $this->resolveCallable($config['callable'], $baseDir);

        $mcp->resource(
            uri: $config['uri'],
            callable: $callable,
            name: $config['name'] ?? null,
            description: $config['description'] ?? null,
        );
    }

    /**
     * Resolve a callable specification.
     *
     * @return callable
     */
    private function resolveCallable(string $spec, string $baseDir): callable
    {
        // Class::method static call
        if (str_contains($spec, '::')) {
            [$class, $method] = explode('::', $spec, 2);
            if (!class_exists($class)) {
                throw new \RuntimeException("Class not found: {$class}");
            }
            return [$class, $method];
        }

        // file.php:function
        if (str_contains($spec, ':')) {
            [$file, $function] = explode(':', $spec, 2);
            $file = $this->resolvePath($file, $baseDir);
            require_once $file;

            if (!function_exists($function)) {
                throw new \RuntimeException("Function not found: {$function} in {$file}");
            }
            return $function;
        }

        // Global function
        if (function_exists($spec)) {
            return $spec;
        }

        throw new \RuntimeException("Cannot resolve callable: {$spec}");
    }

    /**
     * Resolve a file path.
     */
    private function resolveFile(string $file): string
    {
        if (!file_exists($file)) {
            // Try relative to current directory
            $cwd = getcwd();
            if ($cwd !== false && file_exists($cwd . '/' . $file)) {
                $file = $cwd . '/' . $file;
            } else {
                throw new \RuntimeException("File not found: {$file}");
            }
        }

        $resolved = realpath($file);
        if ($resolved === false) {
            throw new \RuntimeException("Cannot resolve file path: {$file}");
        }

        return $resolved;
    }

    /**
     * Resolve a path relative to a base directory.
     */
    private function resolvePath(string $path, string $baseDir): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $baseDir . '/' . $path;
    }
}
