<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Exception;

/**
 * Thrown when a JSON-RPC message names a method no registered message class serves.
 *
 * A subtype of {@see InvalidInputMessageException} so a consumer can tell a
 * genuinely unknown method (-32601) from a known method whose message is
 * malformed (-32600) with a type check instead of comparing message strings.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class UnknownMethodException extends InvalidInputMessageException
{
    public function __construct(
        private readonly string $method,
    ) {
        parent::__construct(\sprintf('Unknown method "%s".', $method));
    }

    public function getMethod(): string
    {
        return $this->method;
    }
}
