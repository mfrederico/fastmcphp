<?php

declare(strict_types=1);

namespace Fastmcphp\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Fastmcphp\Cli\Application;
use Fastmcphp\Cli\ServerLoader;

/**
 * Run an MCP server.
 *
 * Supports multiple server specification formats:
 * - PHP file: server.php (auto-detects $mcp, $server, or $app variable)
 * - Object spec: server.php:myServer (specific variable name)
 * - Config file: fastmcphp.json (loads configuration)
 */
class RunCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('run')
            ->setDescription('Run an MCP server')
            ->addArgument(
                'server',
                InputArgument::OPTIONAL,
                'Server file (server.php), object spec (server.php:app), or config (fastmcphp.json)',
                'fastmcphp.json'
            )
            ->addOption(
                'transport',
                't',
                InputOption::VALUE_REQUIRED,
                'Transport protocol (stdio, http, sse)',
                'stdio'
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'Host to bind to (for http/sse)',
                '127.0.0.1'
            )
            ->addOption(
                'port',
                'p',
                InputOption::VALUE_REQUIRED,
                'Port to bind to (for http/sse)',
                '8000'
            )
            ->addOption(
                'log-level',
                'l',
                InputOption::VALUE_REQUIRED,
                'Log level (DEBUG, INFO, WARNING, ERROR)',
                'INFO'
            )
            ->addOption(
                'no-banner',
                null,
                InputOption::VALUE_NONE,
                'Don\'t show server banner'
            )
            ->addOption(
                'reload',
                null,
                InputOption::VALUE_NONE,
                'Enable auto-reload on file changes'
            )
            ->addOption(
                'stateless',
                null,
                InputOption::VALUE_NONE,
                'Run in stateless mode (no session)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverSpec = $input->getArgument('server');
        $transport = $input->getOption('transport');
        $host = $input->getOption('host');
        $port = (int) $input->getOption('port');
        $logLevel = $input->getOption('log-level');
        $noBanner = $input->getOption('no-banner');
        $reload = $input->getOption('reload');
        $stateless = $input->getOption('stateless');

        // Validate transport
        $validTransports = ['stdio', 'http', 'sse'];
        if (!in_array($transport, $validTransports, true)) {
            $io->error("Invalid transport: {$transport}. Must be one of: " . implode(', ', $validTransports));
            return Command::FAILURE;
        }

        // Load server
        try {
            $loader = new ServerLoader();
            $mcp = $loader->load($serverSpec);
        } catch (\Throwable $e) {
            $io->error("Failed to load server: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Show banner
        if (!$noBanner) {
            $this->showBanner($io, $mcp->getName(), $transport, $host, $port);
        }

        // Handle reload mode
        if ($reload) {
            return $this->runWithReload($io, $serverSpec, $transport, $host, $port, $logLevel, $stateless);
        }

        // Run server
        try {
            $mcp->run(transport: $transport, host: $host, port: $port);
        } catch (\Throwable $e) {
            $io->error("Server error: {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function showBanner(SymfonyStyle $io, string $name, string $transport, string $host, int $port): void
    {
        $io->newLine();
        $io->writeln("<info>FastMCP PHP</info> v" . Application::VERSION);
        $io->writeln("Server: <comment>{$name}</comment>");
        $io->writeln("Transport: <comment>{$transport}</comment>");

        if ($transport !== 'stdio') {
            $io->writeln("Listening: <comment>http://{$host}:{$port}</comment>");
        }

        $io->newLine();
    }

    private function runWithReload(
        SymfonyStyle $io,
        string $serverSpec,
        string $transport,
        string $host,
        int $port,
        string $logLevel,
        bool $stateless,
    ): int {
        if ($transport === 'sse' && !$stateless) {
            $io->error('Reload mode requires --stateless for SSE transport');
            return Command::FAILURE;
        }

        $io->note('Running with auto-reload enabled. Watching for file changes...');

        // Build command for child process
        $cmd = [
            PHP_BINARY,
            $_SERVER['argv'][0],
            'run',
            $serverSpec,
            '--transport=' . $transport,
            '--host=' . $host,
            '--port=' . $port,
            '--log-level=' . $logLevel,
            '--no-banner',
        ];

        if ($stateless) {
            $cmd[] = '--stateless';
        }

        // Watch directory
        $watchDir = dirname(realpath($serverSpec) ?: $serverSpec);

        // Use inotifywait on Linux or fswatch on macOS if available
        $watchCmd = $this->getWatchCommand($watchDir);

        if ($watchCmd === null) {
            $io->warning('File watching not available. Install inotify-tools (Linux) or fswatch (macOS).');
            $io->note('Running without auto-reload.');

            // Fall through to normal execution
            passthru(implode(' ', array_map('escapeshellarg', $cmd)), $exitCode);
            return $exitCode;
        }

        // Run server with file watching
        while (true) {
            $process = proc_open(implode(' ', array_map('escapeshellarg', $cmd)), [
                0 => STDIN,
                1 => STDOUT,
                2 => STDERR,
            ], $pipes);

            if ($process === false) {
                $io->error('Failed to start server process');
                return Command::FAILURE;
            }

            $pid = proc_get_status($process)['pid'];

            // Wait for file change
            exec($watchCmd, $watchOutput, $watchExit);

            // Kill and restart
            $io->note('File change detected. Restarting...');
            proc_terminate($process);
            proc_close($process);

            usleep(100000); // 100ms delay
        }
    }

    private function getWatchCommand(string $dir): ?string
    {
        // Check for inotifywait (Linux)
        exec('which inotifywait 2>/dev/null', $output, $code);
        if ($code === 0) {
            return sprintf(
                'inotifywait -r -e modify,create,delete %s 2>/dev/null',
                escapeshellarg($dir)
            );
        }

        // Check for fswatch (macOS)
        exec('which fswatch 2>/dev/null', $output, $code);
        if ($code === 0) {
            return sprintf(
                'fswatch -1 %s 2>/dev/null',
                escapeshellarg($dir)
            );
        }

        return null;
    }
}
