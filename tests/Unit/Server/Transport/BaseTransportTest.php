<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport;

use Mcp\Exception\LogicException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Transport\BaseTransport;
use Mcp\Server\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class BaseTransportTest extends TestCase
{
    private Uuid $sessionId;

    protected function setUp(): void
    {
        $this->sessionId = Uuid::v4();
    }

    #[TestDox('handling a message without a connected protocol throws')]
    public function testHandleMessageWithoutProtocolThrows(): void
    {
        $transport = $this->createTransport();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('not connected to a protocol');

        $transport->doHandleMessage('{"jsonrpc":"2.0"}', $this->sessionId);
    }

    #[TestDox('fetching outgoing messages without a connected protocol throws')]
    public function testGetOutgoingMessagesWithoutProtocolThrows(): void
    {
        $transport = $this->createTransport();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('call Protocol::connect() before listen()');

        $transport->doGetOutgoingMessages($this->sessionId);
    }

    #[TestDox('fetching pending requests without a connected protocol throws')]
    public function testGetPendingRequestsWithoutProtocolThrows(): void
    {
        $transport = $this->createTransport();

        $this->expectException(LogicException::class);

        $transport->doGetPendingRequests($this->sessionId);
    }

    #[TestDox('checking for a response without a connected protocol throws')]
    public function testCheckForResponseWithoutProtocolThrows(): void
    {
        $transport = $this->createTransport();

        $this->expectException(LogicException::class);

        $transport->doCheckForResponse(1, $this->sessionId);
    }

    #[TestDox('handling a fiber yield without a connected protocol throws')]
    public function testHandleFiberYieldWithoutProtocolThrows(): void
    {
        $transport = $this->createTransport();

        $this->expectException(LogicException::class);

        $transport->doHandleFiberYield(['yield' => true], $this->sessionId);
    }

    #[TestDox('a null fiber yield is ignored even without a connected protocol')]
    public function testNullFiberYieldIsIgnoredWithoutProtocol(): void
    {
        $transport = $this->createTransport();

        $transport->doHandleFiberYield(null, $this->sessionId);

        $this->expectNotToPerformAssertions();
    }

    #[TestDox('session end without a connected protocol stays a no-op so teardown never throws')]
    public function testHandleSessionEndWithoutProtocolIsNoOp(): void
    {
        $transport = $this->createTransport();

        $transport->doHandleSessionEnd($this->sessionId);

        $this->expectNotToPerformAssertions();
    }

    #[TestDox('a connected transport delegates messages to the listener')]
    public function testConnectedTransportDelegatesMessages(): void
    {
        $transport = $this->createTransport();
        $received = [];
        $transport->onMessage(static function (TransportInterface $t, string $payload, ?Uuid $sessionId) use (&$received): void {
            $received[] = [$payload, $sessionId];
        });

        $transport->doHandleMessage('{"jsonrpc":"2.0"}', $this->sessionId);

        $this->assertSame([['{"jsonrpc":"2.0"}', $this->sessionId]], $received);
    }

    #[TestDox('a connected transport delegates to the configured providers')]
    public function testConnectedTransportDelegatesToProviders(): void
    {
        $transport = $this->createTransport();
        $transport->setOutgoingMessagesProvider(static fn (Uuid $sessionId): array => [['message' => 'out', 'context' => []]]);
        $transport->setPendingRequestsProvider(static fn (Uuid $sessionId): array => [['request_id' => 7]]);
        $transport->setResponseFinder(static fn (int $requestId, Uuid $sessionId): Response|Error|null => null);

        $this->assertSame([['message' => 'out', 'context' => []]], $transport->doGetOutgoingMessages($this->sessionId));
        $this->assertSame([['request_id' => 7]], $transport->doGetPendingRequests($this->sessionId));
        $this->assertNull($transport->doCheckForResponse(7, $this->sessionId));
    }

    #[TestDox('providers short-circuit to their defaults when no session is active')]
    public function testProvidersShortCircuitWithoutSession(): void
    {
        $transport = $this->createTransport();
        $transport->setOutgoingMessagesProvider(static function (): array {
            throw new \RuntimeException('Provider must not be called without a session.');
        });
        $transport->setPendingRequestsProvider(static function (): array {
            throw new \RuntimeException('Provider must not be called without a session.');
        });
        $transport->setResponseFinder(static function (): Response|Error|null {
            throw new \RuntimeException('Finder must not be called without a session.');
        });

        $this->assertSame([], $transport->doGetOutgoingMessages(null));
        $this->assertSame([], $transport->doGetPendingRequests(null));
        $this->assertNull($transport->doCheckForResponse(1, null));
    }

    private function createTransport(): ObservableTransport
    {
        return new ObservableTransport();
    }
}

/**
 * @extends BaseTransport<null>
 */
final class ObservableTransport extends BaseTransport
{
    public function send(string $data, array $context): void
    {
    }

    /**
     * @return null
     */
    public function listen(): mixed
    {
        return null;
    }

    public function doHandleMessage(string $payload, ?Uuid $sessionId): void
    {
        $this->handleMessage($payload, $sessionId);
    }

    /**
     * @return array<int, array{message: string, context: array<string, mixed>}>
     */
    public function doGetOutgoingMessages(?Uuid $sessionId): array
    {
        return $this->getOutgoingMessages($sessionId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function doGetPendingRequests(?Uuid $sessionId): array
    {
        return $this->getPendingRequests($sessionId);
    }

    /**
     * @return Response<array<string, mixed>>|Error|null
     */
    public function doCheckForResponse(int $requestId, ?Uuid $sessionId): Response|Error|null
    {
        return $this->checkForResponse($requestId, $sessionId);
    }

    public function doHandleFiberYield(mixed $yielded, ?Uuid $sessionId): void
    {
        $this->handleFiberYield($yielded, $sessionId);
    }

    public function doHandleSessionEnd(?Uuid $sessionId): void
    {
        $this->handleSessionEnd($sessionId);
    }
}
