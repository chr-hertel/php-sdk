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

use Mcp\Schema\Content\ResourceContents;

/**
 * Formats the raw result of a resource read operation into MCP ResourceContent items.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface ResourceResultFormatterInterface
{
    /**
     * @param mixed       $readResult the raw result from the resource handler method
     * @param string      $uri        the URI of the resource that was read
     * @param string|null $mimeType   the MIME type from the resource definition
     * @param mixed       $meta       optional metadata to include in the ResourceContents
     *
     * @return ResourceContents[] array of ResourceContents objects
     *
     * @throws \Mcp\Exception\RuntimeException if the result cannot be formatted
     */
    public function format(mixed $readResult, string $uri, ?string $mimeType = null, mixed $meta = null): array;
}
