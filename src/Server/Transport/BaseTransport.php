<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

use Mcp\Exception\LogicException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Provides a skeletal implementation of the TransportInterface to minimize
 * the effort required to implement this interface.
 *
 * @phpstan-import-type FiberResume from TransportInterface
 * @phpstan-import-type FiberReturn from TransportInterface
 * @phpstan-import-type FiberSuspend from TransportInterface
 * @phpstan-import-type McpFiber from TransportInterface
 *
 * @template TResult
 *
 * @implements TransportInterface<TResult>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
abstract class BaseTransport implements TransportInterface
{
    protected ?Uuid $sessionId = null;

    /**
     * @var McpFiber|null
     */
    protected ?\Fiber $sessionFiber = null;

    protected LoggerInterface $logger;

    /** @var callable(TransportInterface<mixed>, string, ?Uuid): void */
    protected $messageListener;

    /** @var callable(Uuid): void */
    protected $sessionEndListener;

    /** @var callable(Uuid): array<int, array{message: string, context: array<string, mixed>}> */
    protected $outgoingMessagesProvider;

    /** @var callable(Uuid): array<int, array<string, mixed>> */
    protected $pendingRequestsProvider;

    /** @var callable(int, Uuid): Response<array<string, mixed>>|Error|null */
    protected $responseFinder;

    /** @var callable(FiberSuspend|null, ?Uuid): void */
    protected $fiberYieldHandler;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function initialize(): void
    {
    }

    public function close(): void
    {
    }

    public function setSessionId(?Uuid $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * @param McpFiber $fiber
     */
    public function attachFiberToSession(\Fiber $fiber, Uuid $sessionId): void
    {
        $this->sessionFiber = $fiber;
        $this->sessionId = $sessionId;
    }

    public function onMessage(callable $listener): void
    {
        $this->messageListener = $listener;
    }

    public function onSessionEnd(callable $listener): void
    {
        $this->sessionEndListener = $listener;
    }

    public function setOutgoingMessagesProvider(callable $provider): void
    {
        $this->outgoingMessagesProvider = $provider;
    }

    public function setPendingRequestsProvider(callable $provider): void
    {
        $this->pendingRequestsProvider = $provider;
    }

    /**
     * @param callable(int, Uuid):(Response<array<string, mixed>>|Error|null) $finder
     */
    public function setResponseFinder(callable $finder): void
    {
        $this->responseFinder = $finder;
    }

    /**
     * @param callable(FiberSuspend|null, ?Uuid): void $handler
     */
    public function setFiberYieldHandler(callable $handler): void
    {
        $this->fiberYieldHandler = $handler;
    }

    /**
     * @return array<int, array{message: string, context: array<string, mixed>}>
     */
    protected function getOutgoingMessages(?Uuid $sessionId): array
    {
        if (!\is_callable($this->outgoingMessagesProvider)) {
            throw $this->createNotConnectedException();
        }

        if (null === $sessionId) {
            return [];
        }

        return ($this->outgoingMessagesProvider)($sessionId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getPendingRequests(?Uuid $sessionId): array
    {
        if (!\is_callable($this->pendingRequestsProvider)) {
            throw $this->createNotConnectedException();
        }

        if (null === $sessionId) {
            return [];
        }

        return ($this->pendingRequestsProvider)($sessionId);
    }

    /**
     * @phpstan-return FiberResume
     */
    protected function checkForResponse(int $requestId, ?Uuid $sessionId): Response|Error|null
    {
        if (!\is_callable($this->responseFinder)) {
            throw $this->createNotConnectedException();
        }

        if (null === $sessionId) {
            return null;
        }

        return ($this->responseFinder)($requestId, $sessionId);
    }

    /**
     * @param FiberSuspend|null $yielded
     */
    protected function handleFiberYield(mixed $yielded, ?Uuid $sessionId): void
    {
        if (null === $yielded) {
            return;
        }

        if (!\is_callable($this->fiberYieldHandler)) {
            throw $this->createNotConnectedException();
        }

        try {
            ($this->fiberYieldHandler)($yielded, $sessionId);
        } catch (\Throwable $e) {
            $this->logger->error('Fiber yield handler failed.', [
                'exception' => $e,
                'sessionId' => $sessionId?->toRfc4122(),
            ]);
        }
    }

    protected function handleMessage(string $payload, ?Uuid $sessionId): void
    {
        if (!\is_callable($this->messageListener)) {
            throw $this->createNotConnectedException();
        }

        ($this->messageListener)($this, $payload, $sessionId);
    }

    /**
     * Intentionally tolerant of a missing listener: session end is a teardown
     * notification, also reached via close() on error paths, where throwing
     * would mask the original failure instead of surfacing a misuse.
     */
    protected function handleSessionEnd(?Uuid $sessionId): void
    {
        if ($sessionId && \is_callable($this->sessionEndListener)) {
            ($this->sessionEndListener)($sessionId);
        }
    }

    private function createNotConnectedException(): LogicException
    {
        return new LogicException(\sprintf('Transport "%s" is not connected to a protocol; call Protocol::connect() before listen().', static::class));
    }
}
