<?php

declare(strict_types=1);

namespace Fastmcphp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fastmcphp\Server\Transport\HttpTransport;
use Fastmcphp\Server\Server;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;
use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Fastmcphp;
use React\Http\Message\Response as ReactResponse;

/**
 * Tests for HttpTransport with ReactPHP backend.
 *
 * These tests start a real ReactPHP HTTP server on a random port
 * and make actual HTTP requests against it.
 */
class HttpTransportTest extends TestCase
{
    private int $port = 0;
    /** @var resource|false */
    private $serverProcess = false;
    /** @var resource[] */
    private array $pipes = [];

    protected function setUp(): void
    {
        // Find a free port using stream_socket_server with port 0
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "Failed to bind to free port: {$errstr}");
        $address = stream_socket_get_name($server, false);
        fclose($server);
        $this->port = (int) substr($address, strrpos($address, ':') + 1);
    }

    protected function tearDown(): void
    {
        if ($this->serverProcess !== false) {
            // Send SIGTERM
            $status = proc_get_status($this->serverProcess);
            if ($status['running']) {
                posix_kill($status['pid'], SIGTERM);
            }
            foreach ($this->pipes as $pipe) {
                @fclose($pipe);
            }
            proc_close($this->serverProcess);
        }
    }

    /**
     * Start the test server in a subprocess and wait until it's ready.
     */
    private function startServer(?string $authMode = null): void
    {
        $script = __DIR__ . '/../../tests/fixtures/http_test_server.php';
        $cmd = sprintf(
            'exec php %s %d %s',
            escapeshellarg($script),
            $this->port,
            $authMode ? escapeshellarg($authMode) : 'none'
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->serverProcess = proc_open($cmd, $descriptors, $this->pipes);
        $this->assertNotFalse($this->serverProcess, 'Failed to start test server');

        // Wait for the server to be ready (look for startup message)
        stream_set_blocking($this->pipes[1], false);
        $deadline = microtime(true) + 5.0;
        $ready = false;

        while (microtime(true) < $deadline) {
            $line = fgets($this->pipes[1]);
            if ($line !== false && str_contains($line, 'started at')) {
                $ready = true;
                break;
            }
            usleep(50_000);
        }

        $this->assertTrue($ready, 'Server did not start within 5 seconds');
    }

    private function request(
        string $method,
        string $path,
        ?string $body = null,
        array $headers = [],
    ): array {
        $ch = curl_init();
        $url = "http://127.0.0.1:{$this->port}{$path}";

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }
        if (!empty($curlHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        $response = curl_exec($ch);
        $this->assertNotFalse($response, 'curl request failed: ' . curl_error($ch));

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        return [
            'status' => $httpCode,
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }

    private function jsonRpcRequest(string $method, array $params = [], array $headers = []): array
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);

        $headers['Content-Type'] = 'application/json';

        return $this->request('POST', '/mcp', $payload, $headers);
    }

    // =========================================================================
    // Routing Tests
    // =========================================================================

    public function testHealthEndpoint(): void
    {
        $this->startServer();

        $response = $this->request('GET', '/health');

        $this->assertEquals(200, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertEquals(['status' => 'ok'], $body);
    }

    public function testNotFoundForUnknownPath(): void
    {
        $this->startServer();

        $response = $this->request('GET', '/unknown');

        $this->assertEquals(404, $response['status']);
    }

    public function testMethodNotAllowedForGet(): void
    {
        $this->startServer();

        $response = $this->request('GET', '/mcp');

        $this->assertEquals(405, $response['status']);
    }

    public function testOptionsPreflightReturns204(): void
    {
        $this->startServer();

        $response = $this->request('OPTIONS', '/mcp');

        $this->assertEquals(204, $response['status']);
    }

    public function testCorsHeaders(): void
    {
        $this->startServer();

        $response = $this->request('OPTIONS', '/mcp');

        $this->assertStringContainsString('Access-Control-Allow-Origin: *', $response['headers']);
        $this->assertStringContainsString('Access-Control-Allow-Methods', $response['headers']);
        $this->assertStringContainsString('X-API-TOKEN', $response['headers']);
    }

    // =========================================================================
    // JSON-RPC Tests
    // =========================================================================

    public function testEmptyBodyReturns400(): void
    {
        $this->startServer();

        $response = $this->request('POST', '/mcp', '', ['Content-Type' => 'application/json']);

        $this->assertEquals(400, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testInvalidJsonReturnsParseError(): void
    {
        $this->startServer();

        $response = $this->request('POST', '/mcp', 'not json', ['Content-Type' => 'application/json']);

        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals(-32700, $body['error']['code']); // Parse error
    }

    public function testInitializeRequest(): void
    {
        $this->startServer();

        $response = $this->jsonRpcRequest('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'Test', 'version' => '1.0'],
        ]);

        $this->assertEquals(200, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('result', $body);
        $this->assertEquals('Test HTTP Server', $body['result']['serverInfo']['name']);
    }

    public function testToolsListRequest(): void
    {
        $this->startServer();

        $response = $this->jsonRpcRequest('tools/list');

        $this->assertEquals(200, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('result', $body);
        $this->assertArrayHasKey('tools', $body['result']);

        $toolNames = array_column($body['result']['tools'], 'name');
        $this->assertContains('echo', $toolNames);
    }

    public function testToolCallRequest(): void
    {
        $this->startServer();

        // Initialize first
        $this->jsonRpcRequest('initialize', []);

        // Call tool
        $response = $this->jsonRpcRequest('tools/call', [
            'name' => 'echo',
            'arguments' => ['text' => 'Hello from HTTP!'],
        ]);

        $this->assertEquals(200, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertEquals('Hello from HTTP!', $body['result']['content'][0]['text']);
    }

    public function testTrailingSlashOnPath(): void
    {
        $this->startServer();

        $response = $this->request('POST', '/mcp/', json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]), ['Content-Type' => 'application/json']);

        $this->assertEquals(200, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('result', $body);
    }

    // =========================================================================
    // Authentication Tests
    // =========================================================================

    public function testAuthWithBearerToken(): void
    {
        $this->startServer('bearer');

        $response = $this->jsonRpcRequest('tools/list', [], [
            'Authorization' => 'Bearer valid-token',
        ]);

        $this->assertEquals(200, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('result', $body);
    }

    public function testAuthWithApiTokenHeader(): void
    {
        $this->startServer('bearer');

        $response = $this->jsonRpcRequest('tools/list', [], [
            'X-API-TOKEN' => 'valid-token',
        ]);

        $this->assertEquals(200, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('result', $body);
    }

    public function testAuthWithQueryParam(): void
    {
        $this->startServer('bearer');

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);

        $response = $this->request('POST', '/mcp?key=valid-token', $payload, [
            'Content-Type' => 'application/json',
        ]);

        $this->assertEquals(200, $response['status']);
        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('result', $body);
    }

    public function testAuthFailsWithoutToken(): void
    {
        $this->startServer('bearer');

        $response = $this->jsonRpcRequest('tools/list');

        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testAuthFailsWithInvalidToken(): void
    {
        $this->startServer('bearer');

        $response = $this->jsonRpcRequest('tools/list', [], [
            'Authorization' => 'Bearer invalid-token',
        ]);

        $body = json_decode($response['body'], true);
        $this->assertArrayHasKey('error', $body);
    }

    // =========================================================================
    // Transport Construction Tests
    // =========================================================================

    public function testConstructorDefaults(): void
    {
        $transport = new HttpTransport();

        $reflection = new \ReflectionClass($transport);

        $host = $reflection->getProperty('host');
        $this->assertEquals('0.0.0.0', $host->getValue($transport));

        $port = $reflection->getProperty('port');
        $this->assertEquals(8080, $port->getValue($transport));

        $path = $reflection->getProperty('path');
        $this->assertEquals('/mcp', $path->getValue($transport));
    }

    public function testConstructorCustomValues(): void
    {
        $transport = new HttpTransport('127.0.0.1', 9090, '/api');

        $reflection = new \ReflectionClass($transport);

        $host = $reflection->getProperty('host');
        $this->assertEquals('127.0.0.1', $host->getValue($transport));

        $port = $reflection->getProperty('port');
        $this->assertEquals(9090, $port->getValue($transport));

        $path = $reflection->getProperty('path');
        $this->assertEquals('/api', $path->getValue($transport));
    }
}
