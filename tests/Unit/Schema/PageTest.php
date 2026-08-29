<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema;

use Mcp\Schema\Page;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

class PageTest extends TestCase
{
    public function testExposesReferencesAndCursor(): void
    {
        $tool = new Tool('tool1', null, ['type' => 'object', 'properties' => [], 'required' => null], null, null);
        $page = new Page(['tool1' => $tool], 'cursor-abc');

        $this->assertSame(['tool1' => $tool], $page->references);
        $this->assertSame('cursor-abc', $page->nextCursor);
    }

    public function testCursorCanBeNull(): void
    {
        $page = new Page([], null);

        $this->assertSame([], $page->references);
        $this->assertNull($page->nextCursor);
    }
}
