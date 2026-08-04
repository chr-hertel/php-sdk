<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Capability\Logger\ClientLogger;
use Mcp\Exception\LogicException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\InputContext;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Stateless\RequestStateCodec;

/**
 * Context related to a single request. This includes information about the session and
 * will build request-specific objects.
 *
 * This is a stateful object, it should not be reused between requests.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class RequestContext
{
    /**
     * `_meta` key carrying the protocol revision of a single request, introduced
     * with the modern era that replaced the `initialize` handshake.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning
     */
    private const PROTOCOL_VERSION_META_KEY = 'io.modelcontextprotocol/protocolVersion';

    private ?ClientGateway $clientGateway = null;
    private ?ClientLogger $clientLogger = null;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly Request $request,
    ) {
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    /**
     * The protocol revision this request is served under.
     *
     * Modern revisions declare it per request in `_meta`, handshake ones negotiate
     * it once and keep it on the session. Neither is guaranteed to be present — a
     * transport may skip `initialize` entirely — so this falls back to the newest
     * handshake revision, whose rules hold for every revision below it too.
     */
    public function getProtocolVersion(): ProtocolVersion
    {
        $requested = $this->request->getMeta()[self::PROTOCOL_VERSION_META_KEY]
            ?? $this->session->get('protocol_version');

        if (!\is_string($requested)) {
            return ProtocolVersion::latestHandshake();
        }

        return ProtocolVersion::tryFrom($requested) ?? ProtocolVersion::latestHandshake();
    }

    public function getClientGateway(): ClientGateway
    {
        if (null == $this->clientGateway) {
            $this->clientGateway = new ClientGateway($this->session);
        }

        return $this->clientGateway;
    }

    /**
     * What a multi round-trip retry carried back, or null on a first call.
     *
     * Null is the signal to ask: a handler that finds no input context has not
     * been given answers yet and should return an
     * {@see \Mcp\Schema\Result\InputRequiredResult} describing what it needs.
     * Only the modern era has this — the handshake era calls back into the
     * client mid-request instead, so there is nothing to carry.
     */
    public function getInputContext(): ?InputContext
    {
        $context = $this->session->get(InputContext::class);

        return $context instanceof InputContext ? $context : null;
    }

    /**
     * What the client said it can do, as declared on this request.
     *
     * A modern-era server must not ask for input the client cannot supply —
     * an elicitation request to a client without the elicitation capability is
     * a prompt nobody can answer — so a handler checks here before deciding
     * what to put in its inputRequests. Null in the handshake era, where
     * capabilities are connection state rather than request state.
     */
    public function getClientCapabilities(): ?ClientCapabilities
    {
        $meta = $this->session->get(RequestMeta::class);

        return $meta instanceof RequestMeta ? $meta->clientCapabilities : null;
    }

    /**
     * Seals handler context into the opaque string an
     * {@see \Mcp\Schema\Result\InputRequiredResult} carries to the client and
     * gets back on the retry.
     *
     * Signed, not encrypted: the client cannot alter the payload undetected,
     * but it can read it. Nothing secret belongs in here.
     *
     * @param array<string, mixed> $payload
     */
    public function mintRequestState(array $payload): string
    {
        $codec = $this->session->get(RequestStateCodec::class);

        if (!$codec instanceof RequestStateCodec) {
            throw new LogicException('No requestState signing key is configured; call Builder::setRequestState() before returning state from a handler.');
        }

        return $codec->mint($payload);
    }

    public function getClientLogger(): ClientLogger
    {
        if (null === $this->clientLogger) {
            $this->clientLogger = new ClientLogger($this->getClientGateway(), $this->session);
        }

        return $this->clientLogger;
    }
}
