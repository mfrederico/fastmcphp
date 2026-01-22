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
 * Install server in myctobot.ai for a specific tenant/workspace.
 *
 * This command registers an MCP server with myctobot.ai using a tk_ API key.
 * The server configuration is stored in the workspace's mcp_servers table.
 */
class MyctoboCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('install:myctobot')
            ->setDescription('Install server in myctobot.ai workspace')
            ->addArgument(
                'server',
                InputArgument::REQUIRED,
                'Server file to install'
            )
            ->addOption(
                'token',
                't',
                InputOption::VALUE_REQUIRED,
                'tk_ API token for authentication'
            )
            ->addOption(
                'workspace',
                'w',
                InputOption::VALUE_REQUIRED,
                'Workspace/tenant slug'
            )
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'Custom server name (auto-detects if not provided)'
            )
            ->addOption(
                'api-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Myctobot API URL',
                'https://myctobot.ai/api/mcp'
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to myctobot config.ini (alternative to --api-url)'
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
            )
            ->addOption(
                'transport',
                null,
                InputOption::VALUE_REQUIRED,
                'Transport protocol (stdio, http)',
                'stdio'
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'Host for HTTP transport (public URL or IP)'
            )
            ->addOption(
                'port',
                'p',
                InputOption::VALUE_REQUIRED,
                'Port for HTTP transport',
                '8080'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $serverSpec = $input->getArgument('server');
        $token = $input->getOption('token');
        $workspace = $input->getOption('workspace');
        $name = $input->getOption('name');
        $apiUrl = $input->getOption('api-url');
        $configPath = $input->getOption('config');
        $envVars = $input->getOption('env');
        $envFile = $input->getOption('env-file');
        $transport = $input->getOption('transport');
        $host = $input->getOption('host');
        $port = $input->getOption('port');

        // Validate token
        if ($token === null) {
            $token = $this->promptForToken($io);
            if ($token === null) {
                $io->error('API token is required. Use --token or set MYCTOBOT_TOKEN environment variable.');
                return Command::FAILURE;
            }
        }

        if (!$this->isValidTokenFormat($token)) {
            $io->error('Invalid token format. Token must start with tk_ followed by 64 hex characters.');
            return Command::FAILURE;
        }

        // Validate workspace
        if ($workspace === null) {
            $io->error('Workspace is required. Use --workspace to specify the tenant.');
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

        // Get server path
        $serverPath = realpath($serverSpec);
        if ($serverPath === false) {
            $io->error("Server file not found: {$serverSpec}");
            return Command::FAILURE;
        }

        // Parse environment variables
        $env = $this->parseEnvironment($envVars, $envFile);

        // Build server configuration
        $cliPath = realpath($_SERVER['argv'][0]);

        $serverConfig = [
            'name' => $name,
            'workspace' => $workspace,
            'transport' => $transport,
            'command' => PHP_BINARY,
            'args' => [
                $cliPath,
                'run',
                $serverPath,
                "--transport={$transport}",
            ],
            'env' => $env,
        ];

        // Add HTTP-specific config
        if ($transport === 'http') {
            if ($host === null) {
                $io->error('HTTP transport requires --host (public URL or IP where the server is accessible)');
                return Command::FAILURE;
            }
            $serverConfig['url'] = "http://{$host}:{$port}/mcp";
            $serverConfig['args'][] = "--host=0.0.0.0";
            $serverConfig['args'][] = "--port={$port}";
        }

        // Register with myctobot
        $io->note("Registering '{$name}' with myctobot.ai workspace '{$workspace}'...");

        try {
            if ($configPath !== null) {
                $result = $this->registerViaDatabase($serverConfig, $token, $configPath, $io);
            } else {
                $result = $this->registerViaApi($serverConfig, $token, $apiUrl, $io);
            }

            if (!$result['success']) {
                $io->error($result['message']);
                return Command::FAILURE;
            }

            $io->success("Server '{$name}' registered with myctobot.ai workspace '{$workspace}'");

            if ($transport === 'http') {
                $io->note([
                    'To start the server, run:',
                    "  ./bin/fastmcphp run {$serverSpec} --transport=http --host=0.0.0.0 --port={$port}",
                ]);
            } else {
                $io->note('The server will be started automatically by myctobot when needed.');
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error("Registration failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function promptForToken(SymfonyStyle $io): ?string
    {
        // Check environment variable
        $envToken = getenv('MYCTOBOT_TOKEN');
        if ($envToken !== false && $envToken !== '') {
            return $envToken;
        }

        // Interactive prompt if available
        if ($io->isInteractive()) {
            return $io->askHidden('Enter your myctobot tk_ API token');
        }

        return null;
    }

    private function isValidTokenFormat(string $token): bool
    {
        return preg_match('/^tk_[a-f0-9]{64}$/', $token) === 1;
    }

    /**
     * Register via myctobot API endpoint.
     *
     * @param array<string, mixed> $serverConfig
     * @return array{success: bool, message: string}
     */
    private function registerViaApi(array $serverConfig, string $token, string $apiUrl, SymfonyStyle $io): array
    {
        $payload = json_encode([
            'action' => 'register_mcp_server',
            'server' => $serverConfig,
        ]);

        $ch = curl_init($apiUrl);
        if ($ch === false) {
            return ['success' => false, 'message' => 'Failed to initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-TOKEN: ' . $token,
                'X-Workspace: ' . $serverConfig['workspace'],
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => "API request failed: {$error}"];
        }

        if ($httpCode === 401) {
            return ['success' => false, 'message' => 'Invalid or expired API token'];
        }

        if ($httpCode === 403) {
            return ['success' => false, 'message' => 'Access denied to workspace'];
        }

        if ($httpCode >= 400) {
            $data = json_decode($response, true);
            $msg = $data['error'] ?? $data['message'] ?? "HTTP {$httpCode}";
            return ['success' => false, 'message' => "API error: {$msg}"];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Invalid API response'];
        }

        return [
            'success' => $data['success'] ?? true,
            'message' => $data['message'] ?? 'Server registered successfully',
        ];
    }

    /**
     * Register directly to database (for self-hosted myctobot).
     *
     * @param array<string, mixed> $serverConfig
     * @return array{success: bool, message: string}
     */
    private function registerViaDatabase(array $serverConfig, string $token, string $configPath, SymfonyStyle $io): array
    {
        if (!file_exists($configPath)) {
            return ['success' => false, 'message' => "Config file not found: {$configPath}"];
        }

        $config = parse_ini_file($configPath, true);
        if ($config === false || !isset($config['database'])) {
            return ['success' => false, 'message' => 'Invalid config file or missing [database] section'];
        }

        $db = $config['database'];

        try {
            $dsn = sprintf(
                '%s:host=%s;port=%d;dbname=%s;charset=%s',
                $db['type'] ?? 'mysql',
                $db['host'] ?? 'localhost',
                (int) ($db['port'] ?? 3306),
                $db['name'],
                $db['charset'] ?? 'utf8mb4'
            );

            $pdo = new \PDO(
                $dsn,
                $db['user'],
                $db['pass'] ?? '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );

            // Validate token
            $stmt = $pdo->prepare(
                'SELECT ak.id, ak.member_id, ak.scopes_json, m.level
                 FROM apikeys ak
                 JOIN member m ON m.id = ak.member_id
                 WHERE ak.token = ? AND ak.is_active = 1
                 AND (ak.expires_at IS NULL OR ak.expires_at > NOW())
                 AND m.status = "active"'
            );
            $stmt->execute([$token]);
            $apiKey = $stmt->fetch();

            if (!$apiKey) {
                return ['success' => false, 'message' => 'Invalid or expired API token'];
            }

            // Check scope for MCP management
            $scopes = json_decode($apiKey['scopes_json'] ?? '[]', true) ?? [];
            $hasScope = in_array('*:*', $scopes, true)
                || in_array('mcp:*', $scopes, true)
                || in_array('mcp:register', $scopes, true);

            if (!$hasScope && $apiKey['level'] > 50) {
                return ['success' => false, 'message' => 'Insufficient scope for MCP server registration'];
            }

            // Check/create mcp_servers table
            $this->ensureMcpServersTable($pdo);

            // Insert or update server configuration
            $stmt = $pdo->prepare(
                'INSERT INTO mcp_servers (workspace, name, config_json, member_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                 config_json = VALUES(config_json),
                 member_id = VALUES(member_id),
                 updated_at = NOW()'
            );

            $stmt->execute([
                $serverConfig['workspace'],
                $serverConfig['name'],
                json_encode($serverConfig),
                $apiKey['member_id'],
            ]);

            return ['success' => true, 'message' => 'Server registered successfully'];

        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Ensure the mcp_servers table exists.
     */
    private function ensureMcpServersTable(\PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS mcp_servers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                workspace VARCHAR(64) NOT NULL,
                name VARCHAR(128) NOT NULL,
                config_json TEXT NOT NULL,
                member_id INT NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY unique_workspace_name (workspace, name),
                KEY idx_workspace (workspace),
                KEY idx_member (member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
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
