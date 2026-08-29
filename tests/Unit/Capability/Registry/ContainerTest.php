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

use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ReferenceHandler;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testHasReturnsTrueForExplicitlySetInstance(): void
    {
        $container = new Container();
        $container->set(ContainerTestStringDependency::class, new ContainerTestStringDependency('key'));

        $this->assertTrue($container->has(ContainerTestStringDependency::class));
    }

    public function testHasReturnsTrueForAutowirableClass(): void
    {
        $container = new Container();

        $this->assertTrue($container->has(ContainerTestParameterless::class));
        $this->assertTrue($container->has(ContainerTestDefaultsOnly::class));
        $this->assertTrue($container->has(ContainerTestClassDependency::class));
    }

    public function testHasReturnsFalseForUnknownIdentifier(): void
    {
        $container = new Container();

        $this->assertFalse($container->has('App\\DoesNotExist'));
    }

    public function testHasReturnsFalseWhenGetWouldThrow(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(ContainerTestStringDependency::class));
        $this->assertFalse($container->has(ContainerTestUnboundInterfaceDependency::class));
        $this->assertFalse($container->has(ContainerTestInterface::class));
        $this->assertFalse($container->has(ContainerTestAbstract::class));
        $this->assertFalse($container->has(ContainerTestCircularA::class));
    }

    public function testHasReturnsTrueOnceInterfaceDependencyIsBound(): void
    {
        $container = new Container();
        $container->set(ContainerTestInterface::class, new ContainerTestImplementation());

        $this->assertTrue($container->has(ContainerTestUnboundInterfaceDependency::class));
        $this->assertInstanceOf(
            ContainerTestUnboundInterfaceDependency::class,
            $container->get(ContainerTestUnboundInterfaceDependency::class),
        );
    }

    public function testReferenceHandlerFallsBackToDirectInstantiation(): void
    {
        $container = new Container();
        $this->assertFalse($container->has(ContainerTestNewableOnly::class));

        $handler = new ReferenceHandler($container);

        $getClassInstance = new \ReflectionMethod($handler, 'getClassInstance');

        $instance = $getClassInstance->invoke($handler, ContainerTestNewableOnly::class);

        $this->assertInstanceOf(ContainerTestNewableOnly::class, $instance);
    }
}

final class ContainerTestParameterless
{
}

final class ContainerTestDefaultsOnly
{
    public function __construct(
        public string $name = 'default',
        public ?int $count = null,
    ) {
    }
}

final class ContainerTestClassDependency
{
    public function __construct(public ContainerTestParameterless $dependency)
    {
    }
}

final class ContainerTestStringDependency
{
    public function __construct(public string $apiKey)
    {
    }
}

interface ContainerTestInterface
{
}

final class ContainerTestImplementation implements ContainerTestInterface
{
}

final class ContainerTestUnboundInterfaceDependency
{
    public function __construct(public ContainerTestInterface $dependency)
    {
    }
}

abstract class ContainerTestAbstract
{
}

final class ContainerTestCircularA
{
    public function __construct(public ContainerTestCircularB $b)
    {
    }
}

final class ContainerTestCircularB
{
    public function __construct(public ContainerTestCircularA $a)
    {
    }
}

final class ContainerTestNewableOnly
{
    /** @var list<string> */
    public array $items;

    public function __construct(string ...$items)
    {
        $this->items = array_values($items);
    }
}
