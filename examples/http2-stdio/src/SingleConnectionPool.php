<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Http2Stdio;

use Amp\Cancellation;
use Amp\Http\Client\Connection\ConnectionPool;
use Amp\Http\Client\Connection\Http2Connection;
use Amp\Http\Client\Connection\Stream;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;

/**
 * Hands every request to the one HTTP/2 connection that runs over the process pipes.
 *
 * The stock pool opens sockets by host and port, which makes no sense here: there is exactly one
 * peer, reachable only through the pipes of the child process. The request URI still matters -
 * its path and headers travel on the wire - but the authority is never resolved.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class SingleConnectionPool implements ConnectionPool
{
    public function __construct(private readonly Http2Connection $connection)
    {
    }

    public function getStream(Request $request, Cancellation $cancellation): Stream
    {
        $stream = $this->connection->getStream($request);

        if (null === $stream) {
            throw new HttpException('The STDIO connection is closed or out of concurrent streams.');
        }

        return $stream;
    }
}
