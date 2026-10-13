<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery;

use Mcp\Capability\Discovery\SymfonyJsonSchemaGenerator;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

final class SymfonyJsonSchemaGeneratorTest extends TestCase
{
    public function testDescribesParametersFromNativeTypesAndDocBlocks(): void
    {
        $this->assertSchema([
            'type' => 'object',
            'properties' => [
                'city' => ['description' => 'the city to look up', 'type' => 'string'],
                'fields' => ['description' => 'which fields to return', 'type' => 'array', 'items' => ['type' => 'string']],
                'point' => [
                    'type' => 'object',
                    'properties' => ['label' => ['type' => 'string'], 'lat' => ['type' => 'number']], // sorted by TypeInfo
                    'required' => ['lat'],
                    'additionalProperties' => false,
                ],
                'weights' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                'days' => ['type' => 'integer', 'default' => 3],
                'unit' => ['type' => 'string', 'enum' => ['c', 'f'], 'default' => 'c'],
            ],
            'required' => ['city', 'fields', 'point', 'weights'],
        ], 'scalars');
    }

    public function testDescribesObjectParametersFromTheirProperties(): void
    {
        $this->assertSchema([
            'type' => 'object',
            'properties' => [
                'user' => [
                    'description' => 'the user to create',
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'age' => ['type' => 'integer', 'minimum' => 18],
                        'address' => ['anyOf' => [
                            [
                                'type' => 'object',
                                'properties' => [
                                    'street' => ['type' => 'string'],
                                    'country' => ['type' => 'string', 'default' => 'DE', 'minLength' => 2, 'maxLength' => 2],
                                ],
                                'required' => ['street'],
                            ],
                            ['type' => 'null'],
                        ]],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'birthday' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    ],
                    'required' => ['email', 'age'],
                ],
            ],
            'required' => ['user'],
        ], 'dto');
    }

    public function testHoistsDefinitionsOfCyclicClassesToTheRoot(): void
    {
        $category = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/SymfonyJsonSchemaCategory']],
            ],
        ];

        $this->assertSchema([
            'type' => 'object',
            'properties' => ['root' => $category],
            'required' => ['root'],
            '$defs' => ['SymfonyJsonSchemaCategory' => $category],
        ], 'tree');
    }

    public function testDescribesVariadicParametersAsLists(): void
    {
        $this->assertSchema([
            'type' => 'object',
            'properties' => ['numbers' => ['type' => 'array', 'items' => ['type' => 'integer']]],
        ], 'variadic');
    }

    public function testAcceptsAnythingForUntypedParameters(): void
    {
        $this->assertSchema([
            'type' => 'object',
            'properties' => ['anything' => new \stdClass()],
            'required' => ['anything'],
        ], 'untyped');
    }

    public function testOutputSchemaFromReturnTypeIsOptIn(): void
    {
        $method = new \ReflectionMethod(SymfonyJsonSchemaFixture::class, 'returnsDto');

        $this->assertNull((new SymfonyJsonSchemaGenerator())->generateOutputSchema($method));

        $schema = (new SymfonyJsonSchemaGenerator(outputSchemaFromReturnType: true))->generateOutputSchema($method);
        $this->assertNotNull($schema);
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['email', 'age'], $schema['required']);
    }

    public function testExplicitOutputSchemaWins(): void
    {
        $generator = new SymfonyJsonSchemaGenerator(outputSchemaFromReturnType: true);

        $this->assertSame(
            ['type' => 'object', 'properties' => ['explicit' => ['type' => 'boolean']]],
            $generator->generateOutputSchema(new \ReflectionMethod(SymfonyJsonSchemaFixture::class, 'explicitOutput')),
        );
        $this->assertNull($generator->generateOutputSchema(new \ReflectionMethod(SymfonyJsonSchemaFixture::class, 'returnsArray')));
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function assertSchema(array $expected, string $method): void
    {
        $schema = (new SymfonyJsonSchemaGenerator())->generate(new \ReflectionMethod(SymfonyJsonSchemaFixture::class, $method));

        $this->assertSame(
            json_encode($expected, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            json_encode($schema, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );

        new Tool('probe', null, $schema, null, null);
    }
}
