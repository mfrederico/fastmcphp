<?php

declare(strict_types=1);

namespace Fastmcphp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Fastmcphp\Tools\FunctionTool;
use Fastmcphp\Tools\ToolResult;

class FunctionToolTest extends TestCase
{
    public function testSimpleTool(): void
    {
        $tool = new FunctionTool(
            callable: fn(string $text) => $text,
            name: 'echo',
            description: 'Echo text',
        );

        $this->assertEquals('echo', $tool->getName());
        $this->assertEquals('Echo text', $tool->getDescription());

        $result = $tool->execute(['text' => 'Hello']);
        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertFalse($result->isError);
    }

    public function testToolWithMultipleParams(): void
    {
        $tool = new FunctionTool(
            callable: fn(int $a, int $b) => $a + $b,
            name: 'add',
        );

        $result = $tool->execute(['a' => 5, 'b' => 3]);
        $mcpResult = $result->toMcpResult();

        $this->assertEquals('8', $mcpResult['content'][0]['text']);
    }

    public function testToolWithOptionalParams(): void
    {
        $tool = new FunctionTool(
            callable: fn(string $text, bool $upper = false) => $upper ? strtoupper($text) : $text,
            name: 'transform',
        );

        // Without optional param
        $result1 = $tool->execute(['text' => 'hello']);
        $this->assertEquals('hello', $result1->toMcpResult()['content'][0]['text']);

        // With optional param
        $result2 = $tool->execute(['text' => 'hello', 'upper' => true]);
        $this->assertEquals('HELLO', $result2->toMcpResult()['content'][0]['text']);
    }

    public function testToolInputSchema(): void
    {
        $tool = new FunctionTool(
            callable: fn(int $a, string $b, ?float $c = null) => "$a $b $c",
            name: 'test',
        );

        $schema = $tool->getInputSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertArrayHasKey('a', $schema['properties']);
        $this->assertArrayHasKey('b', $schema['properties']);
        $this->assertArrayHasKey('c', $schema['properties']);
        $this->assertEquals('integer', $schema['properties']['a']['type']);
        $this->assertEquals('string', $schema['properties']['b']['type']);
        $this->assertContains('a', $schema['required']);
        $this->assertContains('b', $schema['required']);
        $this->assertNotContains('c', $schema['required']);
    }

    public function testToolToMcpFormat(): void
    {
        $tool = new FunctionTool(
            callable: fn(string $text) => $text,
            name: 'echo',
            description: 'Echo the input',
        );

        $mcp = $tool->toMcpTool();

        $this->assertEquals('echo', $mcp['name']);
        $this->assertEquals('Echo the input', $mcp['description']);
        $this->assertArrayHasKey('inputSchema', $mcp);
    }

    public function testToolErrorHandling(): void
    {
        $tool = new FunctionTool(
            callable: fn() => throw new \RuntimeException('Test error'),
            name: 'fail',
        );

        $result = $tool->execute([]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Test error', $result->toMcpResult()['content'][0]['text']);
    }

    public function testToolReturnsArray(): void
    {
        $tool = new FunctionTool(
            callable: fn() => ['key' => 'value', 'nested' => ['a' => 1]],
            name: 'array_tool',
        );

        $result = $tool->execute([]);
        $content = $result->toMcpResult()['content'][0]['text'];

        $this->assertStringContainsString('key', $content);
        $this->assertStringContainsString('value', $content);
    }
}
