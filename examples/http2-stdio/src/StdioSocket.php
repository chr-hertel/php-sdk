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

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketException;
use Amp\Socket\TlsInfo;
use Amp\Socket\TlsState;
use Amp\Socket\UnixAddress;
use Revolt\EventLoop;

/**
 * Presents a pair of pipes - typically STDIN and STDOUT - as an Amp socket.
 *
 * This is the whole trick behind "HTTP/2 over STDIO": neither the Amp HTTP client nor the Amp
 * HTTP server care that they are talking to a TCP socket. The client needs an
 * {@see Socket} to hand to `Http2Connection`, the server needs a `ReadableStream` plus a
 * `WritableStream` for `Http2Driver::handleClient()`. Both are satisfied by the two pipes a
 * child process already has, so the HTTP/2 framing layer runs unchanged over them.
 *
 * TLS is not available on a pipe, which is fine: HTTP/2 without TLS (h2c) is what this uses,
 * with the connection preface acting as "prior knowledge" - no ALPN, no upgrade dance.
 *
 * @implements \IteratorAggregate<int, string>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StdioSocket implements Socket, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;

    /** @var list<\Closure(): void> */
    private array $onClose = [];

    private readonly SocketAddress $localAddress;

    private readonly SocketAddress $remoteAddress;

    public function __construct(
        private readonly ReadableStream $readable,
        private readonly WritableStream $writable,
        ?SocketAddress $localAddress = null,
        ?SocketAddress $remoteAddress = null,
    ) {
        $this->localAddress = $localAddress ?? new UnixAddress('stdio://local');
        $this->remoteAddress = $remoteAddress ?? new UnixAddress('stdio://remote');

        // A pipe reaching EOF is this socket's equivalent of the peer hanging up.
        $this->readable->onClose($this->close(...));
        $this->writable->onClose($this->close(...));
    }

    public function read(?Cancellation $cancellation = null, ?int $limit = null): ?string
    {
        if (null !== $limit && $this->readable instanceof ReadableResourceStream) {
            return $this->readable->read($cancellation, $limit);
        }

        return $this->readable->read($cancellation);
    }

    public function write(string $bytes): void
    {
        $this->writable->write($bytes);
    }

    public function end(): void
    {
        $this->writable->end();
    }

    public function isReadable(): bool
    {
        return $this->readable->isReadable();
    }

    public function isWritable(): bool
    {
        return $this->writable->isWritable();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $this->readable->close();
        $this->writable->close();

        $onClose = $this->onClose;
        $this->onClose = [];

        foreach ($onClose as $callback) {
            EventLoop::queue($callback);
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        if ($this->closed) {
            EventLoop::queue($onClose);

            return;
        }

        $this->onClose[] = $onClose;
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->localAddress;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->remoteAddress;
    }

    public function setupTls(?Cancellation $cancellation = null): void
    {
        throw new SocketException('TLS is not available on a pipe, HTTP/2 over STDIO speaks h2c with prior knowledge.');
    }

    public function shutdownTls(?Cancellation $cancellation = null): void
    {
        throw new SocketException('TLS is not available on a pipe, HTTP/2 over STDIO speaks h2c with prior knowledge.');
    }

    public function isTlsConfigurationAvailable(): bool
    {
        return false;
    }

    public function getTlsState(): TlsState
    {
        return TlsState::Disabled;
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }
}
