<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Exception;

/**
 * An echoed `requestState` failed verification.
 *
 * The message is a fixed reason code — `malformed`, `mac` or `expired` — and
 * deliberately nothing more. The value came back through the client, so
 * whoever sent it may be probing: a message that distinguished "signed by a
 * different key" from "signed correctly but for another request" would tell an
 * attacker which half of the forgery to fix.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class RequestStateException extends InvalidArgumentException
{
}
