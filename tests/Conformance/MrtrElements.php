<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Conformance;

use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\RequestContext;

/**
 * Fixture handlers for the multi round-trip request scenarios (SEP-2322).
 *
 * Each is the same two-branch shape: with no answers yet, describe what is
 * needed and hand back state; with answers, finish the work. The branch is
 * decided by whether the request carried an input context, which is the only
 * thing distinguishing the two rounds — they are independent requests.
 */
final class MrtrElements
{
    private const ELICIT_KEY = 'user_name';

    public static function elicitation(RequestContext $context): CallToolResult|InputRequiredResult
    {
        $input = $context->getInputContext();

        if (null === $input || !$input->has(self::ELICIT_KEY)) {
            return new InputRequiredResult([
                self::ELICIT_KEY => new ElicitRequest(
                    'What is your name?',
                    new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']),
                ),
            ], requestState: $context->mintRequestState(['stage' => 'awaiting-input']));
        }

        return new CallToolResult([new TextContent(\sprintf('Hello, %s!', self::nameFrom($input->response(self::ELICIT_KEY))))]);
    }

    public static function sampling(RequestContext $context): CallToolResult|InputRequiredResult
    {
        $input = $context->getInputContext();

        if (null === $input || !$input->has('capital')) {
            return new InputRequiredResult([
                'capital' => new CreateSamplingMessageRequest(
                    [new SamplingMessage(Role::User, new TextContent('What is the capital of France?'))],
                    maxTokens: 100,
                ),
            ], requestState: $context->mintRequestState(['stage' => 'awaiting-input']));
        }

        return new CallToolResult([new TextContent('Sampling complete.')]);
    }

    public static function listRoots(RequestContext $context): CallToolResult|InputRequiredResult
    {
        $input = $context->getInputContext();

        if (null === $input || !$input->has('roots')) {
            return new InputRequiredResult(['roots' => new ListRootsRequest()], requestState: $context->mintRequestState(['stage' => 'awaiting-input']));
        }

        return new CallToolResult([new TextContent('Roots received.')]);
    }

    /**
     * Asks for everything it needs in one result — the client answers all
     * three and retries once, rather than making a round trip per question.
     */
    public static function multipleInputs(RequestContext $context): CallToolResult|InputRequiredResult
    {
        $input = $context->getInputContext();

        if (null === $input || !$input->has('user_name')) {
            return new InputRequiredResult([
                'user_name' => new ElicitRequest(
                    'What is your name?',
                    new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']),
                ),
                'greeting' => new CreateSamplingMessageRequest(
                    [new SamplingMessage(Role::User, new TextContent('Generate a greeting'))],
                    maxTokens: 50,
                ),
                'client_roots' => new ListRootsRequest(),
            ], requestState: $context->mintRequestState(['stage' => 'awaiting-input']));
        }

        return new CallToolResult([new TextContent('All inputs received.')]);
    }

    /**
     * Asks twice in sequence, reading which round it is out of the state
     * rather than out of the answers.
     *
     * The distinction matters: a client retrying round two sends only round
     * two's answer, so counting answers would read that as a fresh start and
     * loop forever. The state is the server's only memory across rounds, and
     * this is what it is for.
     */
    public static function multiRound(RequestContext $context): CallToolResult|InputRequiredResult
    {
        $round = $context->getInputContext()?->requestState()['round'] ?? 0;

        if ($round < 1) {
            return new InputRequiredResult([
                'step_one' => new ElicitRequest(
                    'Step one: what is your name?',
                    new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']),
                ),
            ], requestState: $context->mintRequestState(['round' => 1]));
        }

        if ($round < 2) {
            return new InputRequiredResult([
                'step_two' => new ElicitRequest(
                    'Step two: what is your favourite colour?',
                    new ElicitationSchema(['color' => new StringSchemaDefinition('Colour')], ['color']),
                ),
            ], requestState: $context->mintRequestState(['round' => 2]));
        }

        return new CallToolResult([new TextContent('All steps complete.')]);
    }

    /**
     * Reaches the second round only when the echoed state verified, so a
     * tampered value can never produce this result.
     */
    public static function tamperedState(RequestContext $context): CallToolResult|InputRequiredResult
    {
        $input = $context->getInputContext();

        if (null === $input || !$input->has('confirm')) {
            return new InputRequiredResult([
                'confirm' => new ElicitRequest(
                    'Confirm?',
                    new ElicitationSchema(['ok' => new StringSchemaDefinition('Ok')], ['ok']),
                ),
            ], requestState: $context->mintRequestState(['stage' => 'awaiting-confirmation']));
        }

        return new CallToolResult([new TextContent('State verified.')]);
    }

    /**
     * Only ever asks for what the client said it can answer.
     *
     * Servers MUST NOT send an inputRequest for a capability the client did
     * not declare, so the ask is assembled from the declared set rather than
     * from what the tool would ideally like to have.
     */
    public static function capabilities(RequestContext $context): CallToolResult|InputRequiredResult
    {
        $input = $context->getInputContext();

        if (null !== $input && [] !== $input->all()) {
            return new CallToolResult([new TextContent('Input received.')]);
        }

        $capabilities = $context->getClientCapabilities();
        $requests = [];

        if (true === $capabilities?->elicitation) {
            $requests['user_name'] = new ElicitRequest(
                'What is your name?',
                new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']),
            );
        }

        if (true === $capabilities?->sampling) {
            $requests['greeting'] = new CreateSamplingMessageRequest(
                [new SamplingMessage(Role::User, new TextContent('Generate a greeting'))],
                maxTokens: 50,
            );
        }

        // Nothing the client can service: finish rather than ask for something
        // that could never come back.
        if ([] === $requests) {
            return new CallToolResult([new TextContent('No supported input capabilities.')]);
        }

        return new InputRequiredResult($requests, requestState: $context->mintRequestState(['stage' => 'awaiting-input']));
    }

    /**
     * @return array<int, array<string, mixed>>|InputRequiredResult
     */
    public static function prompt(RequestContext $context): array|InputRequiredResult
    {
        $input = $context->getInputContext();

        if (null === $input || !$input->has(self::ELICIT_KEY)) {
            return new InputRequiredResult([
                self::ELICIT_KEY => new ElicitRequest(
                    'What is your name?',
                    new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']),
                ),
            ], requestState: $context->mintRequestState(['stage' => 'awaiting-input']));
        }

        return [['role' => 'user', 'content' => \sprintf('Hello, %s!', self::nameFrom($input->response(self::ELICIT_KEY)))]];
    }

    /**
     * The client's elicitation answer, as far as this fixture cares about it.
     * A declined or cancelled answer has no content, so the greeting falls back
     * rather than the tool failing — the round trip still completed.
     */
    private static function nameFrom(mixed $response): string
    {
        $content = \is_array($response) ? ($response['content'] ?? null) : null;
        $name = \is_array($content) ? ($content['name'] ?? null) : null;

        return \is_string($name) ? $name : 'friend';
    }
}
