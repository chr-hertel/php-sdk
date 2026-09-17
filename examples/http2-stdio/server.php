<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * The child half of the HTTP/2-over-STDIO demo.
 *
 * It is a normal Amp HTTP/2 server, except that its "socket" is the pair of pipes the parent
 * process handed it: requests are parsed off STDIN, responses are framed onto STDOUT. Nothing
 * listens on a port, so nothing else on the machine can reach it.
 *
 * Run it through `client.php`, not directly.
 */

require_once __DIR__.'/vendor/autoload.php';

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\Http2Driver;
use Amp\Http\Server\Driver\SocketClient;
use Amp\Socket\UnixAddress;
use Mcp\Example\Http2Stdio\McpEndpointHandler;
use Mcp\Example\Http2Stdio\StderrLogger;
use Mcp\Example\Http2Stdio\StdioSocket;

use function Amp\ByteStream\getStdin;
use function Amp\ByteStream\getStdout;

// STDOUT carries HTTP/2 frames: a stray warning printed there would corrupt the connection.
ini_set('display_errors', 'stderr');

$logger = new StderrLogger('server');

$socket = new StdioSocket(
    getStdin(),
    getStdout(),
    new UnixAddress('stdio://server'),
    new UnixAddress('stdio://client'),
);

$driver = new Http2Driver(
    requestHandler: new McpEndpointHandler($logger),
    errorHandler: new DefaultErrorHandler(),
    logger: $logger,
    streamTimeout: 60,
    connectionTimeout: 300,
);

$logger->info('Serving HTTP/2 on STDIN/STDOUT, waiting for the client preface');

// Blocks until the parent closes the pipe, exactly like handling one long-lived TCP connection.
$driver->handleClient(new SocketClient($socket), $socket, $socket);

$logger->info('Pipe closed, shutting down');
