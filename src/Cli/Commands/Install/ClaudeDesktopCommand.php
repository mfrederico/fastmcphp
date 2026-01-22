<?php

declare(strict_types=1);

namespace Fastmcphp\Cli\Commands\Install;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Fastmcphp\Cli\ServerLoader;

/**
 * Install server in Claude Desktop configuration.
 */
class ClaudeDesktopCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('install:claude-desktop')
            ->setDescription('Install server in Claude Desktop')
            ->addArgument(
                'server',
                InputArgument::REQUIRED,
                'Server file to install'
            )
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'Custom server name (auto-detects if not provided)'
            )
            ->addOption(
                'env',
                'e',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Environment variables (KEY=VALUE format)'
            )
            ->addOption(
                'env-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Load environment variables from file'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverSpec = $input->getArgument('server');
        $name = $input->getOption('name');
        $envVars = $input->getOption('env');
        $envFile = $input->getOption('env-file');

        // Find config file
        $configPath = $this->getConfigPath();
        if ($configPath === null) {
            $io->error('Could not find Claude Desktop configuration directory.');
            $io->note('Please ensure Claude Desktop is installed.');
            return Command::FAILURE;
        }

        // Load server to get name if not provided
        if ($name === null) {
            try {
                $loader = new ServerLoader();
                $mcp = $loader->load($serverSpec);
                $name = $this->sanitizeName($mcp->getName());
            } catch (\Throwable $e) {
                $io->error("Failed to load server: {$e->getMessage()}");
                return Command::FAILURE;
            }
        }

        // Parse environment variables
        $env = $this->parseEnvironment($envVars, $envFile);

        // Build server command
        $serverPath = realpath($serverSpec);
        if ($serverPath === false) {
            $io->error("Server file not found: {$serverSpec}");
            return Command::FAILURE;
        }

        $command = PHP_BINARY;
        $cliPath = realpath($_SERVER['argv'][0]);

        $args = [
            $cliPath,
            'run',
            $serverPath,
            '--transport=stdio',
        ];

        // Load existing config
        $config = $this->loadConfig($configPath);

        // Add/update server
        $config['mcpServers'][$name] = [
            'command' => $command,
            'args' => $args,
        ];

        if (!empty($env)) {
            $config['mcpServers'][$name]['env'] = $env;
        }

        // Save config
        if (!$this->saveConfig($configPath, $config)) {
            $io->error("Failed to write configuration to: {$configPath}");
            return Command::FAILURE;
        }

        $io->success("Server '{$name}' installed in Claude Desktop");
        $io->note("Restart Claude Desktop to load the new server.");

        return Command::SUCCESS;
    }

    private function getConfigPath(): ?string
    {
        $paths = [];

        switch (PHP_OS_FAMILY) {
            case 'Windows':
                $appData = getenv('APPDATA');
                if ($appData !== false) {
                    $paths[] = $appData . '/Claude/claude_desktop_config.json';
                }
                break;

            case 'Darwin':
                $home = getenv('HOME');
                if ($home !== false) {
                    $paths[] = $home . '/Library/Application Support/Claude/claude_desktop_config.json';
                }
                break;

            default: // Linux
                $xdgConfig = getenv('XDG_CONFIG_HOME');
                $home = getenv('HOME');

                if ($xdgConfig !== false) {
                    $paths[] = $xdgConfig . '/Claude/claude_desktop_config.json';
                }
                if ($home !== false) {
                    $paths[] = $home . '/.config/Claude/claude_desktop_config.json';
                }
                break;
        }

        foreach ($paths as $path) {
            $dir = dirname($path);
            if (is_dir($dir) || @mkdir($dir, 0755, true)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(string $path): array
    {
        if (!file_exists($path)) {
            return ['mcpServers' => []];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ['mcpServers' => []];
        }

        $config = json_decode($content, true);
        if (!is_array($config)) {
            return ['mcpServers' => []];
        }

        if (!isset($config['mcpServers'])) {
            $config['mcpServers'] = [];
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function saveConfig(string $path, array $config): bool
    {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return file_put_contents($path, $json) !== false;
    }

    /**
     * @param array<string> $envVars
     * @return array<string, string>
     */
    private function parseEnvironment(array $envVars, ?string $envFile): array
    {
        $env = [];

        // Load from file first
        if ($envFile !== null && file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    if (str_contains($line, '=')) {
                        [$key, $value] = explode('=', $line, 2);
                        $env[trim($key)] = trim($value);
                    }
                }
            }
        }

        // Override with CLI args
        foreach ($envVars as $var) {
            if (str_contains($var, '=')) {
                [$key, $value] = explode('=', $var, 2);
                $env[trim($key)] = trim($value);
            }
        }

        return $env;
    }

    private function sanitizeName(string $name): string
    {
        // Convert to lowercase, replace spaces and special chars with dashes
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name);
        $name = trim($name, '-');
        return $name ?: 'fastmcphp-server';
    }
}
