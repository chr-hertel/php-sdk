<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Formatter;

use Mcp\Schema\Content\Content;

/**
 * Formats the result of a tool execution into an array of MCP Content items.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface ToolResultFormatterInterface
{
    /**
     * @param mixed $toolExecutionResult the raw value returned by the tool's PHP method
     *
     * @return Content[] the content items for CallToolResult
     *
     * @throws \JsonException if JSON encoding fails for non-Content array/object results
     */
    public function format(mixed $toolExecutionResult): array;
}
