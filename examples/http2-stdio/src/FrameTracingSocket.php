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

use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Socket\TlsState;
use Psr\Log\LoggerInterface;

/**
 * A socket decorator that names the HTTP/2 frames passing through it.
 *
 * Only there to make the demo verifiable: it shows that what travels over the pipe really is
 * HTTP/2 framing - a preface, SETTINGS, HEADERS and DATA per stream - and not a line protocol
 * with HTTP-looking metadata glued on top.
 *
 * @implements \IteratorAggregate<int, string>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class FrameTracingSocket implements Socket, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;

    private const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    private const TYPES = [
        0 => 'DATA',
        1 => 'HEADERS',
        2 => 'PRIORITY',
        3 => 'RST_STREAM',
        4 => 'SETTINGS',
        5 => 'PUSH_PROMISE',
        6 => 'PING',
        7 => 'GOAWAY',
        8 => 'WINDOW_UPDATE',
        9 => 'CONTINUATION',
    ];

    private string $incoming = '';

    private string $outgoing = '';

    public function __construct(
        private readonly Socket $socket,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function read(?Cancellation $cancellation = null, ?int $limit = null): ?string
    {
        $chunk = $this->socket->read($cancellation, $limit);

        if (null !== $chunk) {
            $this->incoming = $this->trace('<-', $this->incoming.$chunk);
        }

        return $chunk;
    }

    public function write(string $bytes): void
    {
        $this->outgoing = $this->trace('->', $this->outgoing.$bytes);

        $this->socket->write($bytes);
    }

    /**
     * Logs every complete frame in the buffer and returns what is left of a partial one.
     */
    private function trace(string $direction, string $buffer): string
    {
        if (str_starts_with($buffer, self::PREFACE)) {
            $this->logger->debug(\sprintf('%s PREFACE', $direction));
            $buffer = substr($buffer, \strlen(self::PREFACE));
        }

        while (\strlen($buffer) >= 9) {
            // 3 bytes length, 1 byte type, 1 byte flags, 4 bytes stream id.
            $header = unpack('Nlength/Ctype/Cflags/Nstream', "\0".substr($buffer, 0, 9));

            if (false === $header) {
                return '';
            }

            $length = $header['length'];

            if (\strlen($buffer) < 9 + $length) {
                break;
            }

            $this->logger->debug(\sprintf(
                '%s %-13s stream=%d length=%d flags=0x%02x',
                $direction,
                self::TYPES[$header['type']] ?? \sprintf('UNKNOWN(%d)', $header['type']),
                $header['stream'] & 0x7FFFFFFF,
                $length,
                $header['flags'],
            ));

            $buffer = substr($buffer, 9 + $length);
        }

        return $buffer;
    }

    public function end(): void
    {
        $this->socket->end();
    }

    public function isReadable(): bool
    {
        return $this->socket->isReadable();
    }

    public function isWritable(): bool
    {
        return $this->socket->isWritable();
    }

    public function close(): void
    {
        $this->socket->close();
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->socket->onClose($onClose);
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->socket->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    public function setupTls(?Cancellation $cancellation = null): void
    {
        $this->socket->setupTls($cancellation);
    }

    public function shutdownTls(?Cancellation $cancellation = null): void
    {
        $this->socket->shutdownTls($cancellation);
    }

    public function isTlsConfigurationAvailable(): bool
    {
        return $this->socket->isTlsConfigurationAvailable();
    }

    public function getTlsState(): TlsState
    {
        return $this->socket->getTlsState();
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->socket->getTlsInfo();
    }
}
