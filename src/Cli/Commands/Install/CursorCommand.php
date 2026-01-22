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
 * Install server in Cursor editor.
 *
 * Supports two modes:
 * - Deeplink: Opens Cursor with a cursor:// URL (default)
 * - Workspace: Creates .cursor/mcp.json in a project directory
 */
class CursorCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('install:cursor')
            ->setDescription('Install server in Cursor editor')
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
                'workspace',
                'w',
                InputOption::VALUE_REQUIRED,
                'Install to workspace directory instead of deeplink'
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
        $workspace = $input->getOption('workspace');
        $envVars = $input->getOption('env');
        $envFile = $input->getOption('env-file');

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

        // Build server config
        $serverPath = realpath($serverSpec);
        if ($serverPath === false) {
            $io->error("Server file not found: {$serverSpec}");
            return Command::FAILURE;
        }

        $cliPath = realpath($_SERVER['argv'][0]);

        $serverConfig = [
            'command' => PHP_BINARY,
            'args' => [
                $cliPath,
                'run',
                $serverPath,
                '--transport=stdio',
            ],
        ];

        if (!empty($env)) {
            $serverConfig['env'] = $env;
        }

        if ($workspace !== null) {
            return $this->installToWorkspace($io, $name, $serverConfig, $workspace);
        }

        return $this->installViaDeeplink($io, $name, $serverConfig);
    }

    /**
     * @param array<string, mixed> $serverConfig
     */
    private function installToWorkspace(SymfonyStyle $io, string $name, array $serverConfig, string $workspace): int
    {
        $cursorDir = rtrim($workspace, '/') . '/.cursor';
        $configPath = $cursorDir . '/mcp.json';

        // Create .cursor directory if needed
        if (!is_dir($cursorDir) && !mkdir($cursorDir, 0755, true)) {
            $io->error("Failed to create directory: {$cursorDir}");
            return Command::FAILURE;
        }

        // Load existing config
        $config = ['mcpServers' => []];
        if (file_exists($configPath)) {
            $content = file_get_contents($configPath);
            if ($content !== false) {
                $existing = json_decode($content, true);
                if (is_array($existing)) {
                    $config = $existing;
                    if (!isset($config['mcpServers'])) {
                        $config['mcpServers'] = [];
                    }
                }
            }
        }

        // Add server
        $config['mcpServers'][$name] = $serverConfig;

        // Save config
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($configPath, $json) === false) {
            $io->error("Failed to write configuration to: {$configPath}");
            return Command::FAILURE;
        }

        $io->success("Server '{$name}' installed to: {$configPath}");

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $serverConfig
     */
    private function installViaDeeplink(SymfonyStyle $io, string $name, array $serverConfig): int
    {
        // Build the config for deeplink
        $fullConfig = [
            'mcpServers' => [
                $name => $serverConfig,
            ],
        ];

        // Encode as base64
        $json = json_encode($fullConfig, JSON_UNESCAPED_SLASHES);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        // Build deeplink URL
        $deeplink = "cursor://anysphere.cursor-deeplink/mcp/install?config={$encoded}";

        $io->note("Opening Cursor with deeplink...");
        $io->writeln("<fg=gray>{$deeplink}</>");

        // Try to open the deeplink
        $opened = $this->openUrl($deeplink);

        if (!$opened) {
            $io->warning('Could not open Cursor automatically.');
            $io->note('Copy and paste this URL into your browser:');
            $io->writeln($deeplink);
        } else {
            $io->success("Server '{$name}' installation initiated in Cursor");
        }

        return Command::SUCCESS;
    }

    private function openUrl(string $url): bool
    {
        $cmd = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        exec("{$cmd} " . escapeshellarg($url) . " 2>/dev/null", $output, $code);

        return $code === 0;
    }

    /**
     * @param array<string> $envVars
     * @return array<string, string>
     */
    private function parseEnvironment(array $envVars, ?string $envFile): array
    {
        $env = [];

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
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name);
        $name = trim($name, '-');
        return $name ?: 'fastmcphp-server';
    }
}
