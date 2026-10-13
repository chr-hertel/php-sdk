<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Capability\Registry\InjectableParameters;
use Mcp\Exception\BadMethodCallException;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Component\JsonSchema\Configuration;
use Symfony\Component\JsonSchema\Schema as JsonSchema;
use Symfony\Component\JsonSchema\SchemaGenerator as JsonSchemaGenerator;
use Symfony\Component\JsonSchema\SchemaGeneratorInterface as JsonSchemaGeneratorInterface;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolverInterface;

/**
 * Generates tool schemas with symfony/json-schema.
 *
 * The parameters of a handler become an array shape, so a single generate() call yields one
 * document whose `$defs` (left over by cyclic classes only) sit at the root of the input schema.
 * Descriptions, defaults and the {@see Schema} attribute stay parameter concerns handled here.
 */
final class SchemaGenerator implements SchemaGeneratorInterface
{
    private readonly JsonSchemaGeneratorInterface $generator;
    private readonly TypeResolverInterface $typeResolver;

    public function __construct(
        private readonly DocBlockParser $docBlockParser = new DocBlockParser(),
        ?JsonSchemaGeneratorInterface $generator = null,
        ?TypeResolverInterface $typeResolver = null,
        private readonly Configuration $configuration = new Configuration(),
        private readonly bool $outputSchemaFromReturnType = false,
    ) {
        $this->generator = $generator ?? JsonSchemaGenerator::create();
        $this->typeResolver = $typeResolver ?? TypeResolver::create();
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(\Reflector $reflection): array
    {
        $reflection = self::assertFunction($reflection);

        $methodSchema = self::schemaAttribute($reflection);
        if (isset($methodSchema['definition'])) {
            return $methodSchema['definition'];
        }

        $shape = [];
        $parameters = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && InjectableParameters::supports($type->getName())) {
                continue;
            }

            if (\in_array(strtolower($parameter->name), ['_session', '_request'], true)) {
                throw new InvalidArgumentException(\sprintf('Handler "%s" has parameter named "%s" which is not allowed. Please change the name of that parameter.', self::handlerName($reflection), $parameter->name));
            }

            try {
                $type = $this->typeResolver->resolve($parameter);
            } catch (UnsupportedException) {
                $type = Type::mixed();
            }
            if ($parameter->isVariadic()) {
                $type = Type::list($type);
            }

            $shape[$parameter->name] = ['type' => $type, 'optional' => $parameter->isOptional()];
            $parameters[$parameter->name] = $parameter;
        }

        // An empty shape is a list to the generator, a tool without arguments still takes an object.
        $schema = $shape ? self::toArray($this->generator->generate(Type::arrayShape($shape), $this->configuration)->flatten()) : ['type' => 'object'];
        unset($schema['$schema'], $schema['additionalProperties']);

        // ArrayShapeType sorts its keys, the signature order is what clients should show.
        $schema['properties'] = array_replace(array_intersect_key($parameters, $schema['properties'] ?? []), $schema['properties'] ?? []);
        if (isset($schema['required'])) {
            $schema['required'] = array_values(array_intersect(array_keys($parameters), $schema['required']));
        }

        $methodProperties = $methodSchema['properties'] ?? [];
        $methodRequired = $methodSchema['required'] ?? [];
        unset($methodSchema['properties'], $methodSchema['required']);
        $schema = [...$schema, ...$methodSchema ?? []];

        $schema['properties'] = [...$schema['properties'] ?? [], ...array_diff_key($methodProperties, $parameters)];
        if ($required = array_values(array_unique([...$schema['required'] ?? [], ...array_diff($methodRequired, array_keys($parameters))]))) {
            $schema['required'] = $required;
        }

        $paramTags = $this->docBlockParser->getParamTags($this->docBlockParser->parseDocBlock($reflection->getDocComment() ?: null));

        foreach ($parameters as $name => $parameter) {
            $property = (array) $schema['properties'][$name];
            if (isset($methodProperties[$name])) {
                $property = [...$property, ...$methodProperties[$name]];
            }

            if (null !== $description = $this->docBlockParser->getParamDescription($paramTags['$'.$name] ?? null)) {
                $property = ['description' => $description, ...$property];
            }

            if ($parameter->isDefaultValueAvailable()) {
                $default = $parameter->getDefaultValue();
                $property['default'] = match (true) {
                    $default instanceof \BackedEnum => $default->value,
                    $default instanceof \UnitEnum => $default->name,
                    default => $default,
                };
            }

            $parameterSchema = self::schemaAttribute($parameter) ?? [];
            if (isset($parameterSchema['definition'])) {
                $property = $parameterSchema['definition'] + array_intersect_key($property, ['default' => true]);
            } elseif (isset($parameterSchema['type'])) {
                $property = [...array_intersect_key($property, ['description' => true, 'default' => true]), ...$parameterSchema];
            } else {
                $property = [...$property, ...$parameterSchema];
            }

            $schema['properties'][$name] = $property ?: new \stdClass();
        }

        if ([] === $schema['properties']) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    public function generateOutputSchema(\Reflector $reflection): ?array
    {
        $reflection = self::assertFunction($reflection);

        foreach ($reflection->getAttributes(McpTool::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            if (null !== $outputSchema = $attribute->newInstance()->outputSchema) {
                return $outputSchema;
            }
        }

        if (!$this->outputSchemaFromReturnType) {
            return null;
        }

        // Only classes: `array` says nothing about the shape and scalars are not objects before 2026-07-28.
        $type = $this->typeResolver->resolve($reflection);
        if (!$type instanceof ObjectType) {
            return null;
        }

        $schema = self::toArray($this->generator->generate($type, $this->configuration)->flatten());
        unset($schema['$schema']);

        return $schema;
    }

    /**
     * Schema::toArray() leaves empty schemas as [], only jsonSerialize() keeps them as objects.
     *
     * @return array<string, mixed>
     */
    private static function toArray(JsonSchema $schema): array
    {
        $convert = static function (mixed $value) use (&$convert): mixed {
            return match (true) {
                $value instanceof \ArrayObject => new \stdClass(),
                \is_array($value) => array_map($convert, $value),
                default => $value,
            };
        };

        return $convert($schema->jsonSerialize());
    }

    private static function assertFunction(\Reflector $reflection): \ReflectionMethod|\ReflectionFunction
    {
        if (!$reflection instanceof \ReflectionMethod && !$reflection instanceof \ReflectionFunction) {
            throw new BadMethodCallException(\sprintf('Schema generation from %s is not supported. Use ReflectionMethod or ReflectionFunction instead.', $reflection::class));
        }

        return $reflection;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function schemaAttribute(\ReflectionFunctionAbstract|\ReflectionParameter $reflection): ?array
    {
        $attributes = $reflection->getAttributes(Schema::class, \ReflectionAttribute::IS_INSTANCEOF);

        return $attributes ? $attributes[0]->newInstance()->toArray() : null;
    }

    private static function handlerName(\ReflectionFunctionAbstract $reflection): string
    {
        return $reflection instanceof \ReflectionMethod ? $reflection->class.'::'.$reflection->name : $reflection->name;
    }
}
