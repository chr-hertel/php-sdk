<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Stateless\RequestMeta;

/**
 * The server's response to a `server/discover` request.
 *
 * This is the modern era's replacement for `InitializeResult`. The difference
 * is not just the method name: `initialize` *negotiates* a single version and
 * remembers it for the connection, whereas `server/discover` merely *reports*
 * every version this server speaks and lets each subsequent request pick one.
 *
 * Server identity travels in the result `_meta` rather than in a body
 * `serverInfo` member (spec PR #3002 removed the latter).
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class DiscoverResult implements ResultInterface
{
    /**
     * @param list<ProtocolVersion> $supportedVersions every revision this server can answer
     */
    public function __construct(
        public readonly array $supportedVersions,
        public readonly ServerCapabilities $capabilities,
        public readonly ?Implementation $serverInfo = null,
        public readonly ?string $instructions = null,
    ) {
        if ([] === $this->supportedVersions) {
            throw new InvalidArgumentException('A DiscoverResult must advertise at least one supported version.');
        }
    }

    /**
     * @return array{
     *     supportedVersions: list<string>,
     *     capabilities: ServerCapabilities,
     *     instructions?: string,
     *     _meta?: array<string, mixed>,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'supportedVersions' => array_values(array_map(
                static fn (ProtocolVersion $version): string => $version->value,
                $this->supportedVersions,
            )),
            'capabilities' => $this->capabilities,
        ];

        if (null !== $this->instructions) {
            $data['instructions'] = $this->instructions;
        }

        if (null !== $this->serverInfo) {
            $data['_meta'] = [RequestMeta::SERVER_INFO => $this->serverInfo];
        }

        return $data;
    }
}
