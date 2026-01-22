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
 * Install server in Claude Code CLI.
 */
class ClaudeCodeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('install:claude-code')
            ->setDescription('Install server in Claude Code CLI')
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

        // Find Claude Code CLI
        $claudeCli = $this->findClaudeCli();
        if ($claudeCli === null) {
            $io->error('Claude Code CLI not found.');
            $io->note('Please install Claude Code: https://claude.ai/code');
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

        $cliPath = realpath($_SERVER['argv'][0]);

        // Build the command for Claude Code
        $serverCommand = PHP_BINARY . ' ' . escapeshellarg($cliPath) . ' run ' . escapeshellarg($serverPath) . ' --transport=stdio';

        // Build claude mcp add command
        $cmd = [
            $claudeCli,
            'mcp',
            'add',
            $name,
        ];

        // Add environment variables
        foreach ($env as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = "{$key}={$value}";
        }

        $cmd[] = '--';
        $cmd[] = $serverCommand;

        $fullCmd = implode(' ', array_map('escapeshellarg', $cmd));

        $io->note("Running: {$fullCmd}");

        // Execute command
        passthru($fullCmd, $exitCode);

        if ($exitCode !== 0) {
            $io->error('Failed to install server in Claude Code');
            return Command::FAILURE;
        }

        $io->success("Server '{$name}' installed in Claude Code");

        return Command::SUCCESS;
    }

    private function findClaudeCli(): ?string
    {
        $home = getenv('HOME') ?: '';

        $candidates = [
            $home . '/.claude/local/claude',
            '/usr/local/bin/claude',
            $home . '/.npm-global/bin/claude',
        ];

        // Add PATH search
        exec('which claude 2>/dev/null', $output, $code);
        if ($code === 0 && !empty($output)) {
            array_unshift($candidates, $output[0]);
        }

        foreach ($candidates as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
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
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name);
        $name = trim($name, '-');
        return $name ?: 'fastmcphp-server';
    }
}
