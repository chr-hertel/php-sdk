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

use Mcp\Schema\Content\PromptMessage;

/**
 * Formats the raw result of a prompt generator into an array of MCP PromptMessages.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface PromptResultFormatterInterface
{
    /**
     * @param mixed $promptGenerationResult expected: array of message structures
     *
     * @return PromptMessage[] array of PromptMessage objects
     *
     * @throws \RuntimeException if the result cannot be formatted
     * @throws \JsonException    if JSON encoding fails
     */
    public function format(mixed $promptGenerationResult): array;
}
