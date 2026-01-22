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
 * Generate MCP configuration JSON for manual installation.
 */
class McpJsonCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('install:mcp-json')
            ->setDescription('Generate MCP configuration JSON')
            ->addArgument(
                'server',
                InputArgument::REQUIRED,
                'Server file to generate config for'
            )
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'Custom server name (auto-detects if not provided)'
            )
            ->addOption(
                'copy',
                null,
                InputOption::VALUE_NONE,
                'Copy configuration to clipboard'
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
        $copy = $input->getOption('copy');
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

        $config = [
            $name => [
                'command' => PHP_BINARY,
                'args' => [
                    $cliPath,
                    'run',
                    $serverPath,
                    '--transport=stdio',
                ],
            ],
        ];

        if (!empty($env)) {
            $config[$name]['env'] = $env;
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($copy) {
            $this->copyToClipboard($json);
            $io->success('Configuration copied to clipboard');
            $io->newLine();
            $io->writeln('<fg=gray>Add this to your MCP configuration file:</>');
        }

        $io->writeln($json);

        return Command::SUCCESS;
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

    private function copyToClipboard(string $text): void
    {
        $cmd = match (PHP_OS_FAMILY) {
            'Darwin' => 'pbcopy',
            'Windows' => 'clip',
            default => 'xclip -selection clipboard',
        };

        $process = popen($cmd, 'w');
        if ($process !== false) {
            fwrite($process, $text);
            pclose($process);
        }
    }
}
