<?php
/**
 * Fastmcphp Serve Command
 *
 * Runs a multi-tenant MCP server that:
 * - Scans a directory for tool definitions
 * - Authenticates via tk_ tokens against workspace databases
 * - Serves all workspaces from a single process
 *
 * Usage:
 *   ./bin/fastmcphp serve \
 *     --tools-dir=/var/www/html/default/myctobot/mcp \
 *     --myctobot-path=/var/www/html/default/myctobot \
 *     --port=8003
 */

declare(strict_types=1);

namespace Fastmcphp\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Fastmcphp\Fastmcphp;
use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;
use Fastmcphp\Server\Middleware\Middleware;
use Fastmcphp\Server\Middleware\MiddlewareContext;
use RedBeanPHP\R as R;

class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('serve')
            ->setDescription('Run multi-tenant MCP server with auto-discovered tools')
            ->addOption(
                'tools-dir',
                't',
                InputOption::VALUE_REQUIRED,
                'Directory containing tool definition files (*.php)'
            )
            ->addOption(
                'myctobot-path',
                'm',
                InputOption::VALUE_REQUIRED,
                'Path to MyCTOBot installation',
                '/var/www/html/default/myctobot'
            )
            ->addOption(
                'port',
                'p',
                InputOption::VALUE_REQUIRED,
                'Port to listen on',
                '8003'
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'Host to bind to',
                '0.0.0.0'
            )
            ->addOption(
                'transport',
                null,
                InputOption::VALUE_REQUIRED,
                'Transport type (sse, http)',
                'sse'
            )
            ->addOption(
                'api-url',
                'a',
                InputOption::VALUE_REQUIRED,
                'MyCTOBot API base URL for authentication',
                'https://myctobot.ai'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $toolsDir = $input->getOption('tools-dir');
        $myctobotPath = $input->getOption('myctobot-path');
        $port = (int) $input->getOption('port');
        $host = $input->getOption('host');
        $transport = $input->getOption('transport');
        $apiUrl = rtrim($input->getOption('api-url'), '/');

        // Validate myctobot path
        if (!is_dir($myctobotPath)) {
            $io->error("MyCTOBot path not found: {$myctobotPath}");
            return Command::FAILURE;
        }

        // Default tools dir to myctobot/mcp if not specified
        if (!$toolsDir) {
            $toolsDir = $myctobotPath . '/mcp';
        }

        if (!is_dir($toolsDir)) {
            $io->error("Tools directory not found: {$toolsDir}");
            return Command::FAILURE;
        }

        // Load MyCTOBot autoloader
        $autoloader = $myctobotPath . '/vendor/autoload.php';
        if (!file_exists($autoloader)) {
            $io->error("MyCTOBot autoloader not found: {$autoloader}");
            return Command::FAILURE;
        }
        require_once $autoloader;

        $io->title('MyCTOBot MCP Gateway');
        $io->listing([
            "MyCTOBot path: {$myctobotPath}",
            "Tools directory: {$toolsDir}",
            "Transport: {$transport}",
            "Listening on: {$host}:{$port}",
            "Auth API: {$apiUrl}",
        ]);

        // Create the MCP server
        $mcp = new Fastmcphp(
            name: 'MyCTOBot Gateway',
            version: '4.2.0',
            instructions: 'Multi-tenant MCP Gateway. Authenticate with tk_ API key and workspace.',
        );

        // Set up multi-tenant auth via API
        $authProvider = new MultiTenantAuthProvider($apiUrl, $myctobotPath);
        $mcp->setAuth($authProvider, required: true);

        // Set up service factory
        $serviceFactory = new ServiceFactory($myctobotPath);

        // Add middleware for member context
        $mcp->addMiddleware(new MemberContextMiddleware($serviceFactory));

        // Create service getter for tools (context set by middleware)
        $getService = function (string $type, ?int $connectionId = null) use ($serviceFactory): object {
            return $serviceFactory->getService($type, $connectionId);
        };

        // Load tools from directory
        $toolFiles = glob($toolsDir . '/*.php');
        $loadedTools = [];

        foreach ($toolFiles as $toolFile) {
            try {
                $loader = require $toolFile;
                if (is_callable($loader)) {
                    $loader($mcp, $getService);
                    $loadedTools[] = basename($toolFile, '.php');
                }
            } catch (\Throwable $e) {
                $io->warning("Failed to load {$toolFile}: {$e->getMessage()}");
            }
        }

        if (empty($loadedTools)) {
            $io->warning("No tools loaded from {$toolsDir}");
        } else {
            $io->success("Loaded tools: " . implode(', ', $loadedTools));
        }

        // Add gateway info tool
        $mcp->tool(
            callable: function () use ($loadedTools, $serviceFactory): string {
                return json_encode([
                    'gateway' => 'MyCTOBot Gateway (fastmcphp)',
                    'version' => '4.2.0',
                    'transport' => 'SSE',
                    'workspace' => $serviceFactory->getWorkspace() ?? 'unknown',
                    'member_id' => $serviceFactory->getMemberId(),
                    'tools_loaded' => $loadedTools,
                ], JSON_PRETTY_PRINT);
            },
            name: 'gateway_info',
            description: 'Get information about this gateway',
        );

        $io->newLine();
        $io->text("Connect with: http://{$host}:{$port}/sse?workspace=<slug>&token=<tk_xxx>");
        $io->newLine();

        // Run the server
        $mcp->run(transport: $transport, host: $host, port: $port);

        return Command::SUCCESS;
    }
}

// ============================================================================
// Multi-Tenant Auth Provider (HTTP API-based)
// ============================================================================

class MultiTenantAuthProvider implements AuthProviderInterface
{
    private string $apiBaseUrl;
    private string $myctobotPath;

    public function __construct(string $apiBaseUrl, string $myctobotPath)
    {
        $this->apiBaseUrl = $apiBaseUrl;
        $this->myctobotPath = $myctobotPath;
    }

    public function authenticate(AuthRequest $request): AuthResult
    {
        $token = $request->getToken();
        if ($token === null) {
            return AuthResult::unauthenticated();
        }

        if (!str_starts_with($token, 'tk_')) {
            return AuthResult::failed('Invalid API key format. Keys must start with tk_');
        }

        $workspace = $request->getQueryParam('workspace')
            ?? $request->getHeader('x-workspace')
            ?? 'default';

        // Call MyCTOBot API to validate token
        $response = $this->callValidateApi($token, $workspace);

        if ($response === null) {
            return AuthResult::failed('Auth API unreachable');
        }

        if (!($response['valid'] ?? false)) {
            return AuthResult::failed($response['error'] ?? 'Invalid token');
        }

        // Create authenticated user from API response
        $user = new AuthenticatedUser(
            id: (string) $response['member_id'],
            name: $response['member_name'] ?? 'unknown',
            email: $response['member_email'] ?? null,
            level: (int) ($response['level'] ?? 100),
            scopes: $response['scopes'] ?? [],
            workspace: $workspace,
            extra: [
                'member_id' => (int) $response['member_id'],
                'connections' => $response['connections'] ?? [],
            ],
        );

        return AuthResult::success($user, $workspace);
    }

    private function callValidateApi(string $token, string $workspace): ?array
    {
        $ch = curl_init("{$this->apiBaseUrl}/api/auth/validate");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'token' => $token,
                'workspace' => $workspace,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("MCP Auth API error: {$error}");
            return null;
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            error_log("MCP Auth API invalid response: {$result}");
            return null;
        }

        // API returns {success: true, data: {...}} or {success: false, error: '...'}
        if ($data['success'] ?? false) {
            return $data['data'] ?? [];
        }

        return ['valid' => false, 'error' => $data['error'] ?? 'Authentication failed'];
    }
}

// ============================================================================
// Service Factory
// ============================================================================

class ServiceFactory
{
    private string $myctobotPath;
    private array $services = [];
    private array $workspaceConnected = [];
    private bool $servicesLoaded = false;

    // Current request context (set by middleware before each tool call)
    private ?int $memberId = null;
    private ?string $workspace = null;

    public function __construct(string $myctobotPath)
    {
        $this->myctobotPath = $myctobotPath;
    }

    /**
     * Set the current request context. Called by middleware before tool execution.
     */
    public function setContext(int $memberId, string $workspace): void
    {
        $this->memberId = $memberId;
        $this->workspace = $workspace;
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    public function getWorkspace(): ?string
    {
        return $this->workspace;
    }

    private function loadServices(): void
    {
        if ($this->servicesLoaded) {
            return;
        }

        $files = [
            '/lib/plugins/AtlassianAuth.php',
            '/services/JiraClient.php',
            '/services/GitHubClient.php',
            '/services/ShopifyClient.php',
            '/services/EncryptionService.php',
        ];

        foreach ($files as $file) {
            $path = $this->myctobotPath . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }

        $this->servicesLoaded = true;
    }

    private function connectWorkspace(string $workspace): void
    {
        if (isset($this->workspaceConnected[$workspace])) {
            R::selectDatabase($workspace);
            return;
        }

        $configFile = $this->myctobotPath . "/conf/config.{$workspace}.ini";
        if (!file_exists($configFile)) {
            $configFile = $this->myctobotPath . '/conf/config.ini';
        }

        if (!file_exists($configFile)) {
            throw new \RuntimeException("Config not found for workspace: {$workspace}");
        }

        $config = parse_ini_file($configFile, true);
        $dbConfig = $config['database'] ?? [];

        $type = $dbConfig['type'] ?? 'sqlite';
        $user = $dbConfig['user'] ?? null;
        $pass = $dbConfig['pass'] ?? null;

        if ($type === 'mysql') {
            $host = $dbConfig['host'] ?? 'localhost';
            $port = $dbConfig['port'] ?? 3306;
            $name = $dbConfig['name'] ?? $workspace;
            $charset = $dbConfig['charset'] ?? 'utf8mb4';
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        } else {
            $dsn = "sqlite:{$this->myctobotPath}/data/{$workspace}.db";
        }

        if (!R::hasDatabase($workspace)) {
            R::addDatabase($workspace, $dsn, $user, $pass);
        }
        R::selectDatabase($workspace);
        $this->workspaceConnected[$workspace] = true;
    }

    public function getService(string $type, ?int $connectionId = null): object
    {
        if (!$this->memberId || !$this->workspace) {
            throw new \RuntimeException("Not authenticated - context not set");
        }

        $this->loadServices();
        $this->connectWorkspace($this->workspace);

        return match ($type) {
            'jira' => $this->getJiraClient(),
            'github' => $this->getGitHubClient(),
            'shopify' => $this->getShopifyClient($connectionId),
            default => throw new \RuntimeException("Unknown service type: {$type}"),
        };
    }

    private function getJiraClient()
    {
        $key = "jira_{$this->workspace}_{$this->memberId}";
        if (!isset($this->services[$key])) {
            $token = R::findOne('atlassiantoken', '(member_id = ? OR is_shared = 1)', [$this->memberId]);
            if (!$token || !$token->cloud_uid) {
                throw new \RuntimeException("No Jira connection found. Connect Jira at /atlassian");
            }
            $this->services[$key] = new \app\services\JiraClient($this->memberId, $token->cloud_uid);
        }
        return $this->services[$key];
    }

    private function getGitHubClient()
    {
        $key = "github_{$this->workspace}_{$this->memberId}";
        if (!isset($this->services[$key])) {
            $ghToken = R::findOne('githubtoken', '(member_id = ? OR is_shared = 1)', [$this->memberId]);
            if (!$ghToken || !$ghToken->access_token) {
                throw new \RuntimeException("No GitHub connection found. Connect GitHub at /github");
            }
            $this->services[$key] = new \app\services\GitHubClient($ghToken->access_token);
        }
        return $this->services[$key];
    }

    private function getShopifyClient(?int $connectionId = null)
    {
        if (!$connectionId) {
            $connections = \app\services\ShopifyClient::getEnabledConnections($this->memberId);
            if (empty($connections)) {
                throw new \RuntimeException("No Shopify connection found. Connect at /shopify");
            }
            $connectionId = reset($connections)->id;
        }

        $client = new \app\services\ShopifyClient($connectionId);
        if (!$client->isConnected()) {
            throw new \RuntimeException("Shopify connection #{$connectionId} is not properly configured");
        }

        return $client;
    }
}

// ============================================================================
// Member Context Middleware
// ============================================================================

class MemberContextMiddleware extends Middleware
{
    private ServiceFactory $serviceFactory;

    public function __construct(ServiceFactory $serviceFactory)
    {
        $this->serviceFactory = $serviceFactory;
    }

    public function beforeToolCall(MiddlewareContext $context, callable $next): mixed
    {
        if ($context->user !== null) {
            $this->serviceFactory->setContext(
                (int) ($context->user->extra['member_id'] ?? 0),
                $context->workspace ?? 'default'
            );
        }
        return $next($context);
    }
}
