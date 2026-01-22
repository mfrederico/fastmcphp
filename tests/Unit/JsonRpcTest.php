<?php

declare(strict_types=1);

namespace Fastmcphp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fastmcphp\Protocol\JsonRpc;
use Fastmcphp\Protocol\Request;
use Fastmcphp\Protocol\Notification;
use Fastmcphp\Protocol\JsonRpcException;
use Fastmcphp\Protocol\ErrorCodes;

class JsonRpcTest extends TestCase
{
    public function testParseRequest(): void
    {
        $json = '{"jsonrpc":"2.0","id":1,"method":"test","params":{"foo":"bar"}}';
        $message = JsonRpc::parse($json);

        $this->assertInstanceOf(Request::class, $message);
        $this->assertEquals(1, $message->id);
        $this->assertEquals('test', $message->method);
        $this->assertEquals(['foo' => 'bar'], $message->params);
    }

    public function testParseNotification(): void
    {
        $json = '{"jsonrpc":"2.0","method":"notify","params":{}}';
        $message = JsonRpc::parse($json);

        $this->assertInstanceOf(Notification::class, $message);
        $this->assertEquals('notify', $message->method);
        $this->assertEquals([], $message->params);
    }

    public function testParseInvalidJson(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(ErrorCodes::PARSE_ERROR);

        JsonRpc::parse('not valid json');
    }

    public function testParseMissingVersion(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(ErrorCodes::INVALID_REQUEST);

        JsonRpc::parse('{"id":1,"method":"test"}');
    }

    public function testParseMissingMethod(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(ErrorCodes::INVALID_REQUEST);

        JsonRpc::parse('{"jsonrpc":"2.0","id":1}');
    }

    public function testEncodeResult(): void
    {
        $json = JsonRpc::encodeResult(1, ['data' => 'test']);
        $decoded = json_decode($json, true);

        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals(1, $decoded['id']);
        $this->assertEquals(['data' => 'test'], $decoded['result']);
    }

    public function testEncodeError(): void
    {
        $json = JsonRpc::encodeError(1, ErrorCodes::NOT_FOUND, 'Not found', ['key' => 'value']);
        $decoded = json_decode($json, true);

        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals(1, $decoded['id']);
        $this->assertEquals(ErrorCodes::NOT_FOUND, $decoded['error']['code']);
        $this->assertEquals('Not found', $decoded['error']['message']);
        $this->assertEquals(['key' => 'value'], $decoded['error']['data']);
    }

    public function testEncodeNotification(): void
    {
        $json = JsonRpc::encodeNotification('event', ['status' => 'ok']);
        $decoded = json_decode($json, true);

        $this->assertEquals('2.0', $decoded['jsonrpc']);
        $this->assertEquals('event', $decoded['method']);
        $this->assertEquals(['status' => 'ok'], $decoded['params']);
        $this->assertArrayNotHasKey('id', $decoded);
    }
}
