<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Registry;

use Mcp\Capability\Formatter\PromptResultFormatterInterface;
use Mcp\Capability\Formatter\ResourceResultFormatterInterface;
use Mcp\Capability\Formatter\ToolResultFormatterInterface;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

class ReferenceFormatterTest extends TestCase
{
    public function testToolReferenceUsesInjectedFormatter(): void
    {
        $formatted = [new TextContent('from custom formatter')];
        $formatter = new class($formatted) implements ToolResultFormatterInterface {
            public mixed $received = null;

            /**
             * @param TextContent[] $formatted
             */
            public function __construct(private readonly array $formatted)
            {
            }

            public function format(mixed $toolExecutionResult): array
            {
                $this->received = $toolExecutionResult;

                return $this->formatted;
            }
        };

        $reference = new ToolReference($this->createTool(), static fn () => 'raw', $formatter);

        $this->assertSame($formatted, $reference->formatResult('raw'));
        $this->assertSame('raw', $formatter->received);
    }

    public function testPromptReferenceUsesInjectedFormatter(): void
    {
        $formatted = [new PromptMessage(Role::User, new TextContent('from custom formatter'))];
        $formatter = new class($formatted) implements PromptResultFormatterInterface {
            /**
             * @param PromptMessage[] $formatted
             */
            public function __construct(private readonly array $formatted)
            {
            }

            public function format(mixed $promptGenerationResult): array
            {
                return $this->formatted;
            }
        };

        $prompt = new Prompt(name: 'test_prompt', description: 'Test prompt', arguments: []);
        $reference = new PromptReference($prompt, static fn () => 'raw', [], $formatter);

        $this->assertSame($formatted, $reference->formatResult('raw'));
    }

    public function testResourceReferenceUsesInjectedFormatter(): void
    {
        $formatted = [new TextResourceContents('file://test.txt', 'text/plain', 'from custom formatter')];
        $formatter = new class($formatted) implements ResourceResultFormatterInterface {
            public mixed $received = null;

            /**
             * @param TextResourceContents[] $formatted
             */
            public function __construct(private readonly array $formatted)
            {
            }

            public function format(mixed $readResult, string $uri, ?string $mimeType = null, mixed $meta = null): array
            {
                $this->received = [$readResult, $uri, $mimeType, $meta];

                return $this->formatted;
            }
        };

        $resource = new ResourceDefinition(uri: 'file://test.txt', name: 'test_resource');
        $reference = new ResourceReference($resource, static fn () => 'raw', $formatter);

        $this->assertSame($formatted, $reference->formatResult('raw', 'file://test.txt', 'text/plain'));
        $this->assertSame(['raw', 'file://test.txt', 'text/plain', null], $formatter->received);
    }

    public function testResourceTemplateReferenceUsesInjectedFormatter(): void
    {
        $formatted = [new TextResourceContents('file://test/1.txt', 'text/plain', 'from custom formatter')];
        $formatter = new class($formatted) implements ResourceResultFormatterInterface {
            public mixed $received = null;

            /**
             * @param TextResourceContents[] $formatted
             */
            public function __construct(private readonly array $formatted)
            {
            }

            public function format(mixed $readResult, string $uri, ?string $mimeType = null, mixed $meta = null): array
            {
                $this->received = [$readResult, $uri, $mimeType, $meta];

                return $this->formatted;
            }
        };

        $template = new ResourceTemplate(uriTemplate: 'file://test/{id}.txt', name: 'test_template');
        $reference = new ResourceTemplateReference($template, static fn () => 'raw', [], $formatter);

        $this->assertSame($formatted, $reference->formatResult('raw', 'file://test/1.txt', 'text/plain'));
        $this->assertSame(['raw', 'file://test/1.txt', 'text/plain', null], $formatter->received);
    }

    private function createTool(): Tool
    {
        return new Tool(
            name: 'test_tool',
            title: null,
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
            description: 'Test tool',
            annotations: null,
        );
    }
}
