<?php

declare(strict_types=1);

namespace Fastmcphp\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Fastmcphp\Cli\Application;

/**
 * Display version information.
 */
class VersionCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('version')
            ->setDescription('Display version information')
            ->addOption('copy', null, InputOption::VALUE_NONE, 'Copy version info to clipboard');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $info = $this->gatherVersionInfo();

        $io->title('FastMCP PHP');
        $io->table([], [
            ['FastMCP PHP', $info['fastmcphp']],
            ['MCP Version', $info['mcp']],
            ['PHP Version', $info['php']],
            ['Swoole', $info['swoole']],
            ['Platform', $info['platform']],
            ['Root Path', $info['root']],
        ]);

        if ($input->getOption('copy')) {
            $text = $this->formatForClipboard($info);
            $this->copyToClipboard($text);
            $io->success('Version info copied to clipboard');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function gatherVersionInfo(): array
    {
        $swooleVersion = extension_loaded('swoole')
            ? phpversion('swoole') ?: 'unknown'
            : 'not installed';

        return [
            'fastmcphp' => Application::VERSION,
            'mcp' => Application::getMcpVersion(),
            'php' => PHP_VERSION,
            'swoole' => $swooleVersion,
            'platform' => php_uname('s') . ' ' . php_uname('m'),
            'root' => Application::getRootPath(),
        ];
    }

    /**
     * @param array<string, string> $info
     */
    private function formatForClipboard(array $info): string
    {
        return implode("\n", [
            "FastMCP PHP: {$info['fastmcphp']}",
            "MCP Version: {$info['mcp']}",
            "PHP Version: {$info['php']}",
            "Swoole: {$info['swoole']}",
            "Platform: {$info['platform']}",
            "Root Path: {$info['root']}",
        ]);
    }

    private function copyToClipboard(string $text): void
    {
        // Platform-specific clipboard commands
        $commands = [
            'darwin' => 'pbcopy',
            'linux' => 'xclip -selection clipboard',
            'win' => 'clip',
        ];

        $os = strtolower(PHP_OS_FAMILY);
        $cmd = $commands[$os] ?? null;

        if ($cmd === null) {
            return;
        }

        $process = popen($cmd, 'w');
        if ($process !== false) {
            fwrite($process, $text);
            pclose($process);
        }
    }
}
