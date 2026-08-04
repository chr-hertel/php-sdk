<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Stateless;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * One modern-era answer, paired with the HTTP status that carries it.
 *
 * The pairing is the point. SEP-2575 fixes specific HTTP statuses to specific
 * JSON-RPC error codes — 404 for a method that does not exist, 400 for a
 * request the server could parse but not accept — so the status is part of the
 * answer rather than something the transport re-derives from the error code.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class StatelessResult
{
    /**
     * @param (\Closure(): \Generator<mixed>)|null $frames set instead of $message when the answer is a stream
     */
    private function __construct(
        public readonly ?MessageInterface $message,
        public readonly int $httpStatus,
        public readonly ?\Closure $frames = null,
    ) {
    }

    /**
     * @param Response<ResultInterface> $response
     */
    public static function ok(Response $response): self
    {
        return new self($response, 200);
    }

    public static function error(Error $error, int $httpStatus): self
    {
        return new self($error, $httpStatus);
    }

    /**
     * A long-lived answer delivered as a sequence of frames rather than one
     * message — `subscriptions/listen` is the only such method today.
     *
     * The generator is deferred rather than eager because the frames are
     * produced over the life of the connection: building them up front would
     * defeat the point of streaming and hold the whole subscription in memory.
     *
     * @param \Closure(): \Generator<mixed> $frames
     */
    public static function stream(\Closure $frames): self
    {
        return new self(null, 200, $frames);
    }

    public function isStream(): bool
    {
        return null !== $this->frames;
    }

    public function isError(): bool
    {
        return $this->message instanceof Error;
    }

    public function toJson(): string
    {
        if (null === $this->message) {
            throw new \LogicException('A streaming result has no single JSON body; iterate its frames instead.');
        }

        return json_encode($this->message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
