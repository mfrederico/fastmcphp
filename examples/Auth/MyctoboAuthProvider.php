<?php

/**
 * Myctobot Auth Provider
 *
 * Production-ready auth provider that integrates with myctobot's
 * authentication system via direct database connection.
 *
 * Features:
 * - tk_ token validation
 * - Workspace-scoped database switching
 * - Scope-based authorization
 * - Permission level checks
 * - Usage tracking
 *
 * Usage in myctobot:
 *   $mcp = new Fastmcphp('Myctobot MCP');
 *   $mcp->setAuth(MyctoboAuthProvider::fromConfig('/path/to/config.ini'));
 *   $mcp->run(transport: 'http');
 */

declare(strict_types=1);

namespace Fastmcphp\Examples\Auth;

use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;
use PDO;
use PDOException;

/**
 * Permission levels matching myctobot's FlightMap.php
 */
class MyctoboLevels
{
    public const ROOT = 1;
    public const ADMIN = 50;
    public const MEMBER = 100;
    public const PUBLIC = 101;
}

/**
 * Auth provider that integrates with myctobot's database.
 *
 * This provider:
 * 1. Extracts tk_ tokens from requests
 * 2. Validates against myctobot's apikeys table
 * 3. Resolves workspace from config or headers
 * 4. Returns scopes for fine-grained authorization
 */
class MyctoboAuthProvider implements AuthProviderInterface
{
    private ?PDO $pdo = null;

    /**
     * @param array{
     *   host: string,
     *   port: int,
     *   name: string,
     *   user: string,
     *   pass: string,
     *   type?: string,
     *   charset?: string
     * } $dbConfig Database configuration
     * @param string|null $workspace Fixed workspace (null to extract from request)
     * @param string $controller MCP controller name for scope checks
     * @param string $method MCP method name for scope checks
     */
    public function __construct(
        private readonly array $dbConfig,
        private readonly ?string $workspace = null,
        private readonly string $controller = 'mcp',
        private readonly string $method = 'call',
    ) {}

    /**
     * Create from a myctobot config.ini file.
     *
     * @param string $configPath Path to config.ini
     * @param string|null $workspace Fixed workspace slug
     */
    public static function fromConfig(string $configPath, ?string $workspace = null): self
    {
        if (!file_exists($configPath)) {
            throw new \InvalidArgumentException("Config file not found: {$configPath}");
        }

        $config = parse_ini_file($configPath, true);

        if ($config === false || !isset($config['database'])) {
            throw new \InvalidArgumentException("Invalid config file or missing [database] section");
        }

        $db = $config['database'];

        return new self(
            dbConfig: [
                'host' => $db['host'] ?? 'localhost',
                'port' => (int) ($db['port'] ?? 3306),
                'name' => $db['name'] ?? throw new \InvalidArgumentException('Database name required'),
                'user' => $db['user'] ?? throw new \InvalidArgumentException('Database user required'),
                'pass' => $db['pass'] ?? '',
                'type' => $db['type'] ?? 'mysql',
                'charset' => $db['charset'] ?? 'utf8mb4',
            ],
            workspace: $workspace,
        );
    }

    /**
     * Create from a workspace-specific config file.
     *
     * @param string $configDir Config directory (e.g., /path/to/myctobot/conf)
     * @param string $workspace Workspace slug
     */
    public static function fromWorkspace(string $configDir, string $workspace): self
    {
        $configPath = rtrim($configDir, '/') . "/config.{$workspace}.ini";

        if (!file_exists($configPath)) {
            throw new \InvalidArgumentException("Workspace config not found: {$configPath}");
        }

        return self::fromConfig($configPath, $workspace);
    }

    public function authenticate(AuthRequest $request): AuthResult
    {
        // Extract token
        $token = $request->getToken();

        if ($token === null) {
            return AuthResult::unauthenticated();
        }

        // Validate token format (must be tk_ + 64 hex chars)
        if (!$this->isValidTokenFormat($token)) {
            return AuthResult::failed('Invalid token format');
        }

        try {
            // Connect to database
            $pdo = $this->getConnection();

            // Look up API key
            $stmt = $pdo->prepare(
                'SELECT id, member_id, token, name, scopes_json, expires_at, is_active, usage_count
                 FROM apikeys
                 WHERE token = ?
                 LIMIT 1'
            );
            $stmt->execute([$token]);
            $apiKey = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$apiKey) {
                return AuthResult::failed('Invalid token');
            }

            // Check if active
            if (!$apiKey['is_active']) {
                return AuthResult::failed('Token is disabled');
            }

            // Check expiration
            if ($apiKey['expires_at'] !== null && strtotime($apiKey['expires_at']) < time()) {
                return AuthResult::failed('Token has expired');
            }

            // Load member
            $stmt = $pdo->prepare(
                'SELECT id, username, display_name, email, level, status
                 FROM member
                 WHERE id = ?
                 LIMIT 1'
            );
            $stmt->execute([$apiKey['member_id']]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                return AuthResult::failed('Member not found');
            }

            if ($member['status'] !== 'active') {
                return AuthResult::failed('Member account is not active');
            }

            // Parse scopes
            $scopes = [];
            if (!empty($apiKey['scopes_json'])) {
                $scopes = json_decode($apiKey['scopes_json'], true) ?? [];
            }

            // Check scope for this request
            if (!$this->checkScope($scopes, $this->controller, $this->method)) {
                return AuthResult::failed('Insufficient scope for this operation');
            }

            // Update usage tracking (use CURRENT_TIMESTAMP for SQLite compatibility)
            $stmt = $pdo->prepare(
                'UPDATE apikeys
                 SET last_used_at = CURRENT_TIMESTAMP,
                     last_used_ip = ?,
                     usage_count = usage_count + 1
                 WHERE id = ?'
            );
            $ip = $request->getHeader('x-forwarded-for') ?? $request->getHeader('remote-addr') ?? 'unknown';
            $stmt->execute([$ip, $apiKey['id']]);

            // Determine workspace
            $workspace = $this->workspace
                ?? $request->getHeader('x-workspace')
                ?? $request->getQueryParam('workspace');

            // Create authenticated user
            $user = new AuthenticatedUser(
                id: (string) $member['id'],
                name: $member['display_name'] ?? $member['username'],
                email: $member['email'] ?? null,
                level: (int) ($member['level'] ?? MyctoboLevels::MEMBER),
                scopes: $scopes,
                workspace: $workspace,
                extra: [
                    'member_id' => $member['id'],
                    'username' => $member['username'],
                    'apikey_id' => $apiKey['id'],
                    'apikey_name' => $apiKey['name'],
                ],
            );

            return AuthResult::success($user, $workspace);

        } catch (PDOException $e) {
            return AuthResult::failed('Database error: ' . $e->getMessage());
        }
    }

    /**
     * Validate tk_ token format.
     */
    private function isValidTokenFormat(string $token): bool
    {
        return preg_match('/^tk_[a-f0-9]{64}$/', $token) === 1;
    }

    /**
     * Check if scopes allow this controller/method.
     *
     * @param array<string> $scopes User's scopes
     * @param string $controller Target controller
     * @param string $method Target method
     */
    private function checkScope(array $scopes, string $controller, string $method): bool
    {
        // Full wildcard
        if (in_array('*:*', $scopes, true) || in_array('*', $scopes, true)) {
            return true;
        }

        // Exact match
        $required = "{$controller}:{$method}";
        if (in_array($required, $scopes, true)) {
            return true;
        }

        // Controller wildcard
        if (in_array("{$controller}:*", $scopes, true)) {
            return true;
        }

        return false;
    }

    /**
     * Get or create PDO connection.
     */
    private function getConnection(): PDO
    {
        if ($this->pdo === null) {
            $type = $this->dbConfig['type'] ?? 'mysql';
            $charset = $this->dbConfig['charset'] ?? 'utf8mb4';

            $dsn = match ($type) {
                'mysql' => sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $this->dbConfig['host'],
                    $this->dbConfig['port'],
                    $this->dbConfig['name'],
                    $charset
                ),
                'pgsql' => sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $this->dbConfig['host'],
                    $this->dbConfig['port'],
                    $this->dbConfig['name']
                ),
                'sqlite' => sprintf('sqlite:%s', $this->dbConfig['name']),
                default => throw new \InvalidArgumentException("Unsupported database type: {$type}"),
            };

            $this->pdo = new PDO(
                $dsn,
                $this->dbConfig['user'],
                $this->dbConfig['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }

        return $this->pdo;
    }

    /**
     * Set an existing PDO connection (for testing or shared connections).
     */
    public function setConnection(PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    /**
     * Close the database connection.
     */
    public function close(): void
    {
        $this->pdo = null;
    }
}
