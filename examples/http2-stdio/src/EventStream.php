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

use Amp\ByteStream\ReadableStream;

use function Amp\ByteStream\split;

/**
 * Reads `text/event-stream` bodies as they arrive, one decoded JSON payload at a time.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class EventStream
{
    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public static function parse(ReadableStream $body): \Generator
    {
        foreach (split($body, "\n\n") as $block) {
            $data = '';

            foreach (explode("\n", trim((string) $block, "\n")) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data .= ltrim(substr($line, 5), ' ');
                }
            }

            if ('' === $data) {
                continue;
            }

            $payload = json_decode($data, true);

            if (\is_array($payload)) {
                yield $payload;
            }
        }
    }
}
