<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Exception;

use Mcp\Exception\BadMethodCallException;
use Mcp\Exception\Exception;
use Mcp\Exception\ExceptionInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\LogicException;
use Mcp\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    /**
     * The SDK-level base classes every concrete exception must descend from,
     * so that `catch (\Mcp\Exception\RuntimeException)` and friends work.
     */
    private const BASE_CLASSES = [
        Exception::class,
        RuntimeException::class,
        InvalidArgumentException::class,
        LogicException::class,
        BadMethodCallException::class,
    ];

    #[DataProvider('provideConcreteExceptions')]
    public function testExceptionExtendsAnMcpBaseClass(string $class): void
    {
        $this->assertTrue(
            is_subclass_of($class, ExceptionInterface::class),
            \sprintf('"%s" must implement "%s".', $class, ExceptionInterface::class),
        );

        foreach (self::BASE_CLASSES as $base) {
            if (is_subclass_of($class, $base)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail(\sprintf('"%s" must extend one of the Mcp\Exception base classes, so it is catchable through them.', $class));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideConcreteExceptions(): iterable
    {
        foreach (glob(\dirname(__DIR__, 3).'/src/Exception/*.php') ?: [] as $file) {
            $class = 'Mcp\Exception\\'.basename($file, '.php');

            if (!class_exists($class) || \in_array($class, self::BASE_CLASSES, true)) {
                continue;
            }

            yield $class => [$class];
        }
    }
}
