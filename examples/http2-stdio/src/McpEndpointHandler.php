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

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;

use function Amp\delay;

/**
 * A miniature Streamable HTTP endpoint, served over a pipe instead of a socket.
 *
 * Everything it does is what the MCP Streamable HTTP transport does over TCP: it reads the
 * `Accept` and `Mcp-Session-Id` headers, hands out a session id on `initialize`, answers a
 * notification with `202 Accepted` and no body, and switches a single response to
 * `text/event-stream` when a call wants to report progress before its result.
 *
 * It is deliberately not built on the SDK's own server: the point of the example is the
 * transport underneath, not the protocol on top of it.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class McpEndpointHandler implements RequestHandler
{
    private const PROTOCOL_VERSION = '2025-11-25';
    private const ENDPOINT = '/mcp';

    /** @var array<string, true> */
    private array $sessions = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handleRequest(Request $request): Response
    {
        $this->logger->info(\sprintf(
            '%s %s (HTTP/%s, stream over one pipe)',
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getProtocolVersion(),
        ));

        if (self::ENDPOINT !== $request->getUri()->getPath()) {
            return $this->error(HttpStatus::NOT_FOUND, \sprintf('No endpoint at "%s".', $request->getUri()->getPath()));
        }

        return match ($request->getMethod()) {
            'POST' => $this->handlePost($request),
            'GET' => $this->handleGet($request),
            'DELETE' => $this->handleDelete($request),
            default => new Response(HttpStatus::METHOD_NOT_ALLOWED, ['allow' => 'GET, POST, DELETE']),
        };
    }

    private function handlePost(Request $request): Response
    {
        $accept = $request->getHeader('accept') ?? '';

        if (!str_contains($accept, 'application/json') || !str_contains($accept, 'text/event-stream')) {
            return $this->error(
                HttpStatus::NOT_ACCEPTABLE,
                'Accept must list both application/json and text/event-stream.',
            );
        }

        $message = json_decode($request->getBody()->buffer(), true);

        if (!\is_array($message)) {
            return $this->error(HttpStatus::BAD_REQUEST, 'Body is not a JSON-RPC message.');
        }

        $method = \is_string($message['method'] ?? null) ? $message['method'] : '';
        $id = $message['id'] ?? null;

        if ('initialize' === $method) {
            return $this->initialize($id);
        }

        if (null === $session = $this->session($request)) {
            return $this->error(HttpStatus::NOT_FOUND, 'Unknown or missing Mcp-Session-Id.');
        }

        // A notification has no id, so there is nothing to answer: status code instead of a body.
        if (null === $id) {
            $this->logger->info(\sprintf('Notification "%s" accepted on session %s', $method, $session));

            return new Response(HttpStatus::ACCEPTED, $this->sessionHeaders($session));
        }

        return match ($method) {
            'tools/list' => $this->json($session, $this->result($id, [
                'tools' => [
                    ['name' => 'add', 'description' => 'Adds two numbers, answered right away.'],
                    ['name' => 'crunch', 'description' => 'Reports progress while it works, answered as a stream.'],
                ],
            ])),
            'tools/call' => $this->callTool($session, $id, $message),
            default => $this->json($session, [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => \sprintf('Method "%s" not found.', $method)],
            ]),
        };
    }

    /**
     * The GET stream of the Streamable HTTP transport: a long-lived response the server pushes
     * notifications into. On a single pipe it costs nothing extra - it is just one more HTTP/2
     * stream next to the request/response pairs.
     */
    private function handleGet(Request $request): Response
    {
        if (!str_contains($request->getHeader('accept') ?? '', 'text/event-stream')) {
            return $this->error(HttpStatus::NOT_ACCEPTABLE, 'Accept must list text/event-stream.');
        }

        if (null === $session = $this->session($request)) {
            return $this->error(HttpStatus::NOT_FOUND, 'Unknown or missing Mcp-Session-Id.');
        }

        $this->logger->info(\sprintf('Opening server-to-client stream for session %s', $session));

        $notifications = (function () use ($session): \Generator {
            foreach (['scanning the index', 'warming the cache', 'ready'] as $step => $text) {
                delay(0.4);

                yield $this->event($session.'-log-'.$step, [
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/message',
                    'params' => ['level' => 'info', 'data' => $text],
                ]);
            }
        })();

        return new Response(
            HttpStatus::OK,
            $this->sessionHeaders($session) + ['content-type' => 'text/event-stream', 'cache-control' => 'no-store'],
            new ReadableIterableStream($notifications),
        );
    }

    private function handleDelete(Request $request): Response
    {
        if (null === $session = $this->session($request)) {
            return $this->error(HttpStatus::NOT_FOUND, 'Unknown or missing Mcp-Session-Id.');
        }

        unset($this->sessions[$session]);
        $this->logger->info(\sprintf('Session %s terminated', $session));

        return new Response(HttpStatus::NO_CONTENT);
    }

    private function initialize(mixed $id): Response
    {
        $session = bin2hex(random_bytes(8));
        $this->sessions[$session] = true;

        $this->logger->info(\sprintf('Session %s created', $session));

        return $this->json($session, $this->result($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'http2-over-stdio-demo', 'version' => '1.0.0'],
        ]));
    }

    /**
     * @param array<string, mixed> $message
     */
    private function callTool(string $session, mixed $id, array $message): Response
    {
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];
        $name = \is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ('add' === $name) {
            $sum = (int) ($arguments['a'] ?? 0) + (int) ($arguments['b'] ?? 0);

            // Answered while the streaming call below is still running: both share the pipe.
            return $this->json($session, $this->result($id, [
                'content' => [['type' => 'text', 'text' => (string) $sum]],
            ]));
        }

        if ('crunch' !== $name) {
            return $this->json($session, [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32602, 'message' => \sprintf('Unknown tool "%s".', $name)],
            ]);
        }

        $steps = max(1, min(10, (int) ($arguments['steps'] ?? 3)));
        $token = \is_scalar($params['_meta']['progressToken'] ?? null) ? (string) $params['_meta']['progressToken'] : 'crunch';

        $stream = (function () use ($id, $steps, $token, $session): \Generator {
            for ($step = 1; $step <= $steps; ++$step) {
                delay(0.25);

                yield $this->event(\sprintf('%s-progress-%d', $session, $step), [
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/progress',
                    'params' => [
                        'progressToken' => $token,
                        'progress' => $step,
                        'total' => $steps,
                        'message' => \sprintf('crunched %d of %d', $step, $steps),
                    ],
                ]);
            }

            yield $this->event($session.'-result', $this->result($id, [
                'content' => [['type' => 'text', 'text' => \sprintf('crunched %d chunks', $steps)]],
            ]));
        })();

        return new Response(
            HttpStatus::OK,
            $this->sessionHeaders($session) + ['content-type' => 'text/event-stream', 'cache-control' => 'no-store'],
            new ReadableIterableStream($stream),
        );
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(string $id, array $payload): string
    {
        return \sprintf("id: %s\nevent: message\ndata: %s\n\n", $id, $this->encode($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(string $session, array $payload): Response
    {
        return new Response(
            HttpStatus::OK,
            $this->sessionHeaders($session) + ['content-type' => 'application/json'],
            $this->encode($payload),
        );
    }

    private function error(int $status, string $message): Response
    {
        $this->logger->warning(\sprintf('%d %s', $status, $message));

        return new Response($status, ['content-type' => 'application/json'], $this->encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32600, 'message' => $message],
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function sessionHeaders(string $session): array
    {
        return ['mcp-session-id' => $session, 'mcp-protocol-version' => self::PROTOCOL_VERSION];
    }

    private function session(Request $request): ?string
    {
        $session = $request->getHeader('mcp-session-id');

        return null !== $session && isset($this->sessions[$session]) ? $session : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
