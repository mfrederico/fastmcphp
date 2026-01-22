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
 * Run development server with MCP Inspector.
 *
 * Launches the server with the MCP Inspector UI for debugging
 * and testing tool calls interactively.
 */
class DevCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('dev')
            ->setDescription('Run development server with MCP Inspector')
            ->addArgument(
                'server',
                InputArgument::OPTIONAL,
                'Server file or config',
                'fastmcphp.json'
            )
            ->addOption(
                'ui-port',
                null,
                InputOption::VALUE_REQUIRED,
                'Port for MCP Inspector UI',
                '5173'
            )
            ->addOption(
                'server-port',
                null,
                InputOption::VALUE_REQUIRED,
                'Port for MCP proxy server',
                '3000'
            )
            ->addOption(
                'inspector-version',
                null,
                InputOption::VALUE_REQUIRED,
                'MCP Inspector version to use',
                'latest'
            )
            ->addOption(
                'reload',
                null,
                InputOption::VALUE_NEGATABLE,
                'Enable/disable auto-reload',
                true
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'Host to bind to',
                '127.0.0.1'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverSpec = $input->getArgument('server');
        $uiPort = $input->getOption('ui-port');
        $serverPort = $input->getOption('server-port');
        $inspectorVersion = $input->getOption('inspector-version');
        $reload = $input->getOption('reload');
        $host = $input->getOption('host');

        // Check for npx
        $npx = $this->findNpx();
        if ($npx === null) {
            $io->error('npx not found. Please install Node.js to use the MCP Inspector.');
            $io->note('You can still run the server directly with: fastmcphp run ' . $serverSpec);
            return Command::FAILURE;
        }

        // Validate server can be loaded
        try {
            $loader = new ServerLoader();
            $mcp = $loader->load($serverSpec);
            $serverName = $mcp->getName();
        } catch (\Throwable $e) {
            $io->error("Failed to load server: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Show banner
        $io->newLine();
        $io->writeln("<info>FastMCP PHP Development Server</info>");
        $io->writeln("Server: <comment>{$serverName}</comment>");
        $io->writeln("Inspector UI: <comment>http://{$host}:{$uiPort}</comment>");
        $io->writeln("Proxy Server: <comment>http://{$host}:{$serverPort}</comment>");
        $io->newLine();

        // Build server command
        $phpBinary = PHP_BINARY;
        $cliPath = realpath($_SERVER['argv'][0]);
        $serverCommand = "{$phpBinary} {$cliPath} run " . escapeshellarg($serverSpec) . " --transport=stdio";

        if (!$reload) {
            $serverCommand .= ' --no-reload';
        }

        // Build inspector command
        $inspectorPackage = $inspectorVersion === 'latest'
            ? '@anthropic/mcp-inspector'
            : "@anthropic/mcp-inspector@{$inspectorVersion}";

        $env = [
            'CLIENT_PORT' => $uiPort,
            'SERVER_PORT' => $serverPort,
        ];

        $envPrefix = '';
        foreach ($env as $key => $value) {
            $envPrefix .= "{$key}={$value} ";
        }

        $inspectorCmd = "{$envPrefix}{$npx} {$inspectorPackage} {$serverCommand}";

        $io->note("Starting MCP Inspector...");
        $io->writeln("<fg=gray>{$inspectorCmd}</>");
        $io->newLine();

        // Run inspector
        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open($inspectorCmd, $descriptors, $pipes, null, null);

        if ($process === false) {
            $io->error('Failed to start MCP Inspector');
            return Command::FAILURE;
        }

        // Wait for process
        $status = proc_close($process);

        return $status === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Find npx executable.
     */
    private function findNpx(): ?string
    {
        $candidates = ['npx'];

        // Windows variants
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates = ['npx.cmd', 'npx.exe', 'npx'];
        }

        foreach ($candidates as $cmd) {
            exec("which {$cmd} 2>/dev/null", $output, $code);
            if ($code === 0 && !empty($output)) {
                return $output[0];
            }

            // Windows fallback
            if (PHP_OS_FAMILY === 'Windows') {
                exec("where {$cmd} 2>nul", $output, $code);
                if ($code === 0 && !empty($output)) {
                    return $output[0];
                }
            }
        }

        return null;
    }
}
