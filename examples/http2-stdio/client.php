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
 * HTTP/2 over STDIO: HTTP semantics without a socket.
 *
 * Spawns `server.php` as a child process and speaks plain HTTP/2 (h2c, prior knowledge) over its
 * STDIN/STDOUT pipes with the Amp HTTP client. Headers, status codes, streaming bodies and
 * multiplexing all work as they do over TCP - there is simply no port, no TLS and no network.
 *
 * Usage: php examples/http2-stdio/client.php
 */

require_once __DIR__.'/vendor/autoload.php';

use Amp\Future;
use Amp\Http\Client\Connection\Http2Connection;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Process\Process;
use Amp\Socket\UnixAddress;
use Mcp\Example\Http2Stdio\EventStream;
use Mcp\Example\Http2Stdio\FrameTracingSocket;
use Mcp\Example\Http2Stdio\SingleConnectionPool;
use Mcp\Example\Http2Stdio\StderrLogger;
use Mcp\Example\Http2Stdio\StdioSocket;

use function Amp\async;
use function Amp\ByteStream\getStderr;
use function Amp\ByteStream\pipe;

$start = microtime(true);

$out = static function (string $line) use ($start): void {
    printf("%7.0f ms  %s\n", (microtime(true) - $start) * 1000, $line);
};

$headline = static function (string $title): void {
    echo "\n".$title."\n".str_repeat('-', strlen($title))."\n";
};

// 1. The "connection": a child process, and the two pipes it was born with.
$process = Process::start([\PHP_BINARY, __DIR__.'/server.php']);

// Its STDERR stays a plain log channel, only STDOUT carries frames.
async(static fn () => pipe($process->getStderr(), getStderr()))->ignore();

$socket = new StdioSocket(
    $process->getStdout(),
    $process->getStdin(),
    new UnixAddress('stdio://client'),
    new UnixAddress('stdio://server'),
);

// H2_TRACE=1 names every frame on the way in and out, to show the pipe really carries HTTP/2.
if (false !== getenv('H2_TRACE')) {
    $socket = new FrameTracingSocket($socket, new StderrLogger('wire'));
}

// 2. The HTTP/2 connection on top of it. `initialize()` sends the h2c preface and SETTINGS,
//    which is all the handshake there is without TLS and ALPN.
$connection = new Http2Connection($socket, connectDuration: 0.0, tlsHandshakeDuration: null);
$connection->initialize();

$http = (new HttpClientBuilder())
    ->usingPool(new SingleConnectionPool($connection))
    ->build();

$requestCount = 0;

/**
 * Builds a request with the headers an MCP Streamable HTTP client would send over TCP.
 *
 * @param array<string, mixed>|null $payload
 */
$request = static function (string $method, string $path, ?array $payload = null, ?string $session = null, string $accept = 'application/json, text/event-stream') use (&$requestCount): Request {
    ++$requestCount;

    $request = new Request('http://stdio'.$path, $method, null !== $payload ? json_encode($payload, \JSON_THROW_ON_ERROR) : '');
    $request->setHeader('accept', $accept);

    if (null !== $payload) {
        $request->setHeader('content-type', 'application/json');
    }

    if (null !== $session) {
        $request->setHeader('mcp-session-id', $session);
        $request->setHeader('mcp-protocol-version', '2025-11-25');
    }

    return $request;
};

$describe = static function (string $label, Response $response) use ($out): void {
    $out(sprintf(
        '%-26s HTTP/%s %d %s  [content-type: %s]',
        $label,
        $response->getProtocolVersion(),
        $response->getStatus(),
        $response->getReason(),
        $response->getHeader('content-type') ?? '-',
    ));
};

try {
    // 3. initialize: the session id comes back in a response header, not in the payload.
    $headline('Handshake: response headers travel over the pipe');

    $response = $http->request($request('POST', '/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25', 'clientInfo' => ['name' => 'stdio-h2-demo', 'version' => '1.0.0']],
    ]));

    $describe('initialize', $response);
    $session = $response->getHeader('mcp-session-id') ?? '';
    $out(sprintf('%-26s mcp-session-id: %s', '', $session));
    $out(sprintf('%-26s %s', '', $response->getBody()->buffer()));

    // 4. A long-lived server-to-client stream, held open while other requests come and go.
    $headline('Concurrency: one pipe, four HTTP/2 streams at once');

    $serverStream = async(static function () use ($http, $request, $session, $describe, $out): void {
        $response = $http->request($request('GET', '/mcp', null, $session, 'text/event-stream'));
        $describe('GET /mcp (open stream)', $response);

        foreach (EventStream::parse($response->getBody()) as $notification) {
            $out(sprintf('%-26s <- %s: %s', '', $notification['method'] ?? '?', $notification['params']['data'] ?? ''));
        }

        $out(sprintf('%-26s stream ended', 'GET /mcp'));
    });

    // Three calls dispatched together. The slow streaming one does not block the quick ones:
    // over a line-delimited STDIO transport they would queue up behind it.
    $crunch = async(static function () use ($http, $request, $session, $describe, $out): void {
        $response = $http->request($request('POST', '/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'crunch', 'arguments' => ['steps' => 4], '_meta' => ['progressToken' => 'job-1']],
        ], $session));

        $describe('tools/call crunch', $response);

        foreach (EventStream::parse($response->getBody()) as $message) {
            if (isset($message['result'])) {
                $out(sprintf('%-26s <- result: %s', '', $message['result']['content'][0]['text'] ?? ''));

                continue;
            }

            $out(sprintf('%-26s <- progress %s/%s', '', $message['params']['progress'] ?? '?', $message['params']['total'] ?? '?'));
        }
    });

    $add = async(static function () use ($http, $request, $session, $describe, $out): void {
        $response = $http->request($request('POST', '/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'add', 'arguments' => ['a' => 40, 'b' => 2]],
        ], $session));

        $describe('tools/call add', $response);
        $out(sprintf('%-26s <- %s', '', $response->getBody()->buffer()));
    });

    $list = async(static function () use ($http, $request, $session, $describe): void {
        $describe('tools/list', $http->request($request('POST', '/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/list',
        ], $session)));
    });

    Future\await([$serverStream, $crunch, $add, $list]);

    // 5. Status codes keep their meaning: 202 for a notification, 404 for a wrong path.
    $headline('Status codes: the rest of the HTTP contract');

    $describe('notifications/initialized', $http->request($request('POST', '/mcp', [
        'jsonrpc' => '2.0',
        'method' => 'notifications/initialized',
    ], $session)));

    $describe('GET /nope', $http->request($request('GET', '/nope', null, $session)));

    $describe('DELETE /mcp (end session)', $http->request($request('DELETE', '/mcp', null, $session)));
} finally {
    // 6. Closing the pipes is the equivalent of closing the socket: the child sees EOF and exits.
    $connection->close();
    $socket->close();

    $exitCode = $process->join();

    echo "\n";
    $out(sprintf('Done: %d requests, one pipe, no socket. Server exited with code %d.', $requestCount, $exitCode));
}
