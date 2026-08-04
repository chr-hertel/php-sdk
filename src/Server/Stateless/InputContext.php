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

/**
 * What a retry brought back with it: the client's answers to a previous
 * {@see \Mcp\Schema\Result\InputRequiredResult}, and the state that result
 * carried, already verified.
 *
 * A handler uses this to tell the two rounds apart. On the first call there is
 * no input context at all; on the retry there is one, and whatever the handler
 * sealed into `requestState` is available again — which is the only memory it
 * gets, since nothing on the server survives between the rounds.
 *
 * Answers are keyed by the identifiers the handler chose for its
 * `inputRequests`. Keys it does not recognize are simply never asked for: the
 * spec has servers ignore what they do not need rather than reject it.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InputContext
{
    /**
     * @param array<string, mixed> $responses    client answers, keyed as the inputRequests were
     * @param array<string, mixed> $requestState the verified payload the server sealed last round
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly array $requestState = [],
    ) {
    }

    /**
     * The client's answer for `$key`, or null when it did not provide one.
     */
    public function response(string $key): mixed
    {
        return $this->responses[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->responses);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->responses;
    }

    /**
     * @return array<string, mixed>
     */
    public function requestState(): array
    {
        return $this->requestState;
    }
}
