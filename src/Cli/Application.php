<?php

declare(strict_types=1);

namespace Fastmcphp\Cli;

use Symfony\Component\Console\Application as ConsoleApplication;
use Fastmcphp\Cli\Commands\VersionCommand;
use Fastmcphp\Cli\Commands\RunCommand;
use Fastmcphp\Cli\Commands\DevCommand;
use Fastmcphp\Cli\Commands\InspectCommand;
use Fastmcphp\Cli\Commands\Install\ClaudeDesktopCommand;
use Fastmcphp\Cli\Commands\Install\ClaudeCodeCommand;
use Fastmcphp\Cli\Commands\Install\CursorCommand;
use Fastmcphp\Cli\Commands\Install\McpJsonCommand;

/**
 * FastMCP PHP CLI Application.
 */
class Application extends ConsoleApplication
{
    public const VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct('fastmcphp', self::VERSION);

        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        $this->add(new VersionCommand());
        $this->add(new RunCommand());
        $this->add(new DevCommand());
        $this->add(new InspectCommand());
        $this->add(new ClaudeDesktopCommand());
        $this->add(new ClaudeCodeCommand());
        $this->add(new CursorCommand());
        $this->add(new McpJsonCommand());
    }

    /**
     * Get the FastMCP PHP root directory.
     */
    public static function getRootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Get MCP protocol version.
     */
    public static function getMcpVersion(): string
    {
        return '2024-11-05';
    }
}
