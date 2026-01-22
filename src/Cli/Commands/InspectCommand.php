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
use Fastmcphp\Fastmcphp;

/**
 * Inspect an MCP server's capabilities.
 *
 * Displays information about registered tools, resources, and prompts.
 * Can output in text or JSON format.
 */
class InspectCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('inspect')
            ->setDescription('Inspect an MCP server\'s capabilities')
            ->addArgument(
                'server',
                InputArgument::OPTIONAL,
                'Server file or config',
                'fastmcphp.json'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (text, json, mcp)',
                'text'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file for JSON format'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverSpec = $input->getArgument('server');
        $format = $input->getOption('format');
        $outputFile = $input->getOption('output');

        // Validate options
        if ($outputFile !== null && $format === 'text') {
            $io->error('--output requires --format=json or --format=mcp');
            return Command::FAILURE;
        }

        $validFormats = ['text', 'json', 'mcp'];
        if (!in_array($format, $validFormats, true)) {
            $io->error("Invalid format: {$format}. Must be one of: " . implode(', ', $validFormats));
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

        // Get server info
        $info = $this->getServerInfo($mcp);

        // Output based on format
        switch ($format) {
            case 'json':
            case 'mcp':
                return $this->outputJson($io, $info, $outputFile, $format === 'mcp');

            case 'text':
            default:
                return $this->outputText($io, $info);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getServerInfo(Fastmcphp $mcp): array
    {
        $server = $mcp->getServer();

        // Get tools
        $tools = [];
        foreach ($server->getTools() as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        // Get resources
        $resources = [];
        foreach ($server->getResources() as $resource) {
            $resources[] = [
                'uri' => $resource->getUri(),
                'name' => $resource->getName(),
                'description' => $resource->getDescription(),
                'mimeType' => $resource->getMimeType(),
            ];
        }

        // Get resource templates
        $templates = [];
        foreach ($server->getResourceTemplates() as $template) {
            $templates[] = [
                'uriTemplate' => $template->getUriTemplate(),
                'name' => $template->getName(),
                'description' => $template->getDescription(),
                'mimeType' => $template->getMimeType(),
            ];
        }

        // Get prompts
        $prompts = [];
        foreach ($server->getPrompts() as $prompt) {
            $prompts[] = [
                'name' => $prompt->getName(),
                'description' => $prompt->getDescription(),
                'arguments' => $prompt->getArguments(),
            ];
        }

        return [
            'name' => $mcp->getName(),
            'version' => Application::VERSION,
            'mcpVersion' => Application::getMcpVersion(),
            'tools' => $tools,
            'resources' => $resources,
            'resourceTemplates' => $templates,
            'prompts' => $prompts,
            'capabilities' => [
                'tools' => count($tools) > 0,
                'resources' => count($resources) > 0 || count($templates) > 0,
                'prompts' => count($prompts) > 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $info
     */
    private function outputText(SymfonyStyle $io, array $info): int
    {
        $io->title($info['name']);

        $io->table([], [
            ['FastMCP PHP', $info['version']],
            ['MCP Version', $info['mcpVersion']],
        ]);

        // Tools
        $io->section('Tools (' . count($info['tools']) . ')');
        if (empty($info['tools'])) {
            $io->text('<fg=gray>No tools registered</>');
        } else {
            $rows = [];
            foreach ($info['tools'] as $tool) {
                $rows[] = [$tool['name'], $tool['description'] ?? ''];
            }
            $io->table(['Name', 'Description'], $rows);
        }

        // Resources
        $io->section('Resources (' . count($info['resources']) . ')');
        if (empty($info['resources'])) {
            $io->text('<fg=gray>No resources registered</>');
        } else {
            $rows = [];
            foreach ($info['resources'] as $resource) {
                $rows[] = [$resource['uri'], $resource['name'] ?? '', $resource['mimeType'] ?? 'text/plain'];
            }
            $io->table(['URI', 'Name', 'MIME Type'], $rows);
        }

        // Resource Templates
        $io->section('Resource Templates (' . count($info['resourceTemplates']) . ')');
        if (empty($info['resourceTemplates'])) {
            $io->text('<fg=gray>No resource templates registered</>');
        } else {
            $rows = [];
            foreach ($info['resourceTemplates'] as $template) {
                $rows[] = [$template['uriTemplate'], $template['name'] ?? '', $template['mimeType'] ?? 'text/plain'];
            }
            $io->table(['URI Template', 'Name', 'MIME Type'], $rows);
        }

        // Prompts
        $io->section('Prompts (' . count($info['prompts']) . ')');
        if (empty($info['prompts'])) {
            $io->text('<fg=gray>No prompts registered</>');
        } else {
            $rows = [];
            foreach ($info['prompts'] as $prompt) {
                $argCount = count($prompt['arguments'] ?? []);
                $rows[] = [$prompt['name'], $prompt['description'] ?? '', $argCount . ' args'];
            }
            $io->table(['Name', 'Description', 'Arguments'], $rows);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function outputJson(SymfonyStyle $io, array $info, ?string $outputFile, bool $mcpFormat): int
    {
        if ($mcpFormat) {
            // Convert to MCP protocol format
            $output = [
                'serverInfo' => [
                    'name' => $info['name'],
                    'version' => $info['version'],
                ],
                'capabilities' => $info['capabilities'],
                'tools' => $info['tools'],
                'resources' => $info['resources'],
                'resourceTemplates' => $info['resourceTemplates'],
                'prompts' => $info['prompts'],
            ];
        } else {
            $output = $info;
        }

        $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($outputFile !== null) {
            $written = file_put_contents($outputFile, $json);
            if ($written === false) {
                $io->error("Failed to write to: {$outputFile}");
                return Command::FAILURE;
            }
            $io->success("Server info written to: {$outputFile}");
        } else {
            $io->writeln($json);
        }

        return Command::SUCCESS;
    }
}
