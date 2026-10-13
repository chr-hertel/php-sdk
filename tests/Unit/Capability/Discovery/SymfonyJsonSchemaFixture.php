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

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\JsonSchema\Attribute\JsonSchemaConstraint;
use Symfony\Component\Validator\Constraints as Assert;

enum SymfonyJsonSchemaUnit: string
{
    case Celsius = 'c';
    case Fahrenheit = 'f';
}

final class SymfonyJsonSchemaAddress
{
    public function __construct(
        public string $street,
        #[Assert\Length(min: 2, max: 2)]
        public string $country = 'DE',
    ) {
    }
}

final class SymfonyJsonSchemaUser
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[Assert\Email]
        public string $email,
        #[JsonSchemaConstraint(minimum: 18)]
        public int $age,
        public ?SymfonyJsonSchemaAddress $address = null,
        public array $tags = [],
        public ?\DateTimeImmutable $birthday = null,
    ) {
    }
}

final class SymfonyJsonSchemaCategory
{
    public string $name;

    /** @var list<SymfonyJsonSchemaCategory> */
    public array $children = [];
}

final class SymfonyJsonSchemaFixture
{
    /**
     * @param string                            $city    the city to look up
     * @param list<string>                      $fields  which fields to return
     * @param array{lat: float, label?: string} $point
     * @param array<string, int>                $weights
     */
    public function scalars(string $city, array $fields, array $point, array $weights, int $days = 3, SymfonyJsonSchemaUnit $unit = SymfonyJsonSchemaUnit::Celsius): void
    {
    }

    /**
     * @param SymfonyJsonSchemaUser $user the user to create
     */
    public function dto(SymfonyJsonSchemaUser $user, RequestContext $context): void
    {
    }

    public function tree(SymfonyJsonSchemaCategory $root): void
    {
    }

    public function variadic(int ...$numbers): void
    {
    }

    public function untyped($anything): void // @phpstan-ignore missingType.parameter
    {
    }

    public function returnsDto(): SymfonyJsonSchemaUser
    {
        return new SymfonyJsonSchemaUser('a@b.c', 18);
    }

    #[McpTool(outputSchema: ['type' => 'object', 'properties' => ['explicit' => ['type' => 'boolean']]])]
    public function explicitOutput(): SymfonyJsonSchemaUser
    {
        return new SymfonyJsonSchemaUser('a@b.c', 18);
    }

    public function returnsArray(): array
    {
        return [];
    }
}
