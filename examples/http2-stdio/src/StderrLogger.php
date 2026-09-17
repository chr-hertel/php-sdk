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

use Psr\Log\AbstractLogger;

/**
 * Writes log records to STDERR, because STDOUT carries HTTP/2 frames.
 *
 * The same rule as with the STDIO transport of the SDK: anything that is not protocol traffic -
 * log lines, warnings, `var_dump()` leftovers - has to leave through STDERR, or it corrupts the
 * frame stream the peer is parsing.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StderrLogger extends AbstractLogger
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream
     */
    public function __construct(private readonly string $prefix = 'server', $stream = null)
    {
        $this->stream = $stream ?? \STDERR;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $line = \sprintf('[%s] %-7s %s', $this->prefix, strtoupper((string) $level), $message);

        if ([] !== $context) {
            $line .= ' '.json_encode($context, \JSON_UNESCAPED_SLASHES);
        }

        fwrite($this->stream, $line.\PHP_EOL);
    }
}
