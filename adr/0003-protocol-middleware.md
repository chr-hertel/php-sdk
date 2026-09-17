# 0003 — A protocol middleware layer, in two phases rather than a wrapped call

- Status: **Proposed** (sketch for discussion — nothing implemented)
- Date: 2026-09-17
- Follows: [0002 — Widen `ExtensionInterface` to the axes extensions actually use](0002-extension-interface-axes.md)

## Context

ADR 0002 declined a middleware axis on `ExtensionInterface` because the layer an extension would
contribute to does not exist. This is that layer.

### What exists today

| Layer | State | Owned by |
|---|---|---|
| HTTP | PSR-15, eight middlewares, `defaultMiddleware()` / `[]` to disable | the **transport constructor** |
| Protocol (decoded `Request` → `Response`) | PSR-14 events that *mutate* (`RequestEvent::setRequest()`, `ErrorEvent`'s replacement) but cannot short-circuit or wrap | — |
| stdio | nothing | — |

Four things want the missing middle:

- **Auth.** `OAuthRequestMetaMiddleware` decodes the JSON-RPC body, injects `_meta.oauth` into
  each message (batch-aware), and re-encodes it, purely to get HTTP-layer identity into
  `RequestContext`. A protocol concern implemented by rewriting bytes one layer down.
- **Interceptors (SEP-2624), invoker side.** The SEP says SDKs are expected to ship the chain
  orchestration. Its events span `tools/call`, `resources/read` — and `sampling/createMessage`,
  `elicitation/create`, `roots/list`.
- **Users.** Rate limiting, per-tenant quotas, audit logging, and "reject this call outright"
  are all short-circuits, which events cannot express.
- **stdio parity.** Everything above is transport-independent, and today stdio has no seam at all.

### What the execution model imposes

This is the part that decides the shape, and it rules out the obvious design.

1. **The handler call is not a stack frame you can wrap.** `$handler->handle()` runs inside a
   `\Fiber` (`src/Server/Protocol.php:294`) that suspends whenever the handler asks the client
   something. On suspension `handleRequest()` sends the outbound request, attaches the fiber to
   the session and **returns** (`:300-316`). A classic `$next($request)` decorator's "after" code
   would run at the suspension, not at completion.
2. **The response is sometimes written by the transport, bypassing `Protocol` entirely.** After a
   suspension the transport drives the resumes and, on termination, `json_encode`s the fiber's
   return and writes it directly — `StdioTransport::handleFiberTermination()` (`:174`) and
   `StreamableHttpTransport::handleFiberTermination()` (`:313`). `Protocol::sendResponse()` is
   never called for that exchange. See *Prerequisite* below.
3. **The two phases can be in different processes.** On `2026-07-28`, `ElicitationReplay` answers
   an ask with `input_required`; the client re-sends and the handler is **re-entered from the
   top** (`StatelessProtocol::run()`, `:571`). Request phase and response phase of one logical
   operation are separated by a process boundary, which is exactly why `requestState` is signed
   and serialized.
4. **There are three inbound dispatch sites and one outbound choke point.**
   Inbound: `Server\Protocol::handleRequest()`, `Server\Stateless\StatelessProtocol` (generator-
   driven), and `Client\Protocol` for server→client requests. Outbound: everything funnels
   through `ClientGateway::suspend()` (`:450`) — `sample()`, `elicit()`, `elicitUrl()`,
   `listRoots()`, `request()` — with `notify()` suspending alongside it.

Constraints 1–3 mean **an onion with `finally` semantics is not implementable here.** Any design
that promises "code runs after the handler, guaranteed, in the same frame" is lying on three of
the four call sites.

## Decision

**Two-phase hooks, not a wrapped call.** Middleware sees a request phase and a result phase as
two separate invocations, so nothing depends on the handler returning within a stack frame.

```php
namespace Mcp\Server\Middleware;   // deliberately NOT Psr\Http\Server

interface MiddlewareInterface
{
    /**
     * Before dispatch. Return the request (possibly replaced) to continue, or a
     * Response/Error to short-circuit — the handler never runs.
     */
    public function onRequest(Request $request, MiddlewareContext $context): Request|Response|Error;

    /**
     * Once the exchange settles, however many suspensions later, and whichever
     * call site produced it.
     */
    public function onResult(Response|Error $result, Request $request, MiddlewareContext $context): Response|Error;
}

final class MiddlewareContext
{
    public function __construct(
        public readonly Direction $direction,       // Inbound | Outbound
        public readonly SessionInterface $session,
        public readonly LoggerInterface $logger,
    ) {
    }
}

enum Direction
{
    case Inbound;    // a request this side serves
    case Outbound;   // a request this side asks of its peer
}
```

With a skeletal base, for the same reason ADR 0002 gives and `BaseTransport` already sets:

```php
/**
 * Provides a skeletal implementation of MiddlewareInterface: both phases pass
 * through, so a middleware overrides only the one it cares about.
 */
abstract class AbstractMiddleware implements MiddlewareInterface
{
    public function onRequest(Request $request, MiddlewareContext $context): Request|Response|Error
    {
        return $request;
    }

    public function onResult(Response|Error $result, Request $request, MiddlewareContext $context): Response|Error
    {
        return $result;
    }
}
```

### Ordering and short-circuiting

Onion order without the onion: `onRequest` runs in registration order, `onResult` in reverse.
When a middleware short-circuits, the ones **before** it still get their `onResult` with the
short-circuit result; the ones after it are never entered, in neither phase. That is PSR-15's
mental model, preserved without requiring a `$next` that cannot exist here.

### Registration

On the builder, next to the handlers it wraps — not on the transport, which is where the HTTP
stack lives and is the reason auth cannot currently be an extension:

```php
$server = Server::builder()
    ->addMiddleware(new RateLimiter($redis))                          // inbound (default)
    ->addMiddleware(new AuditLog($psr3), Direction::Inbound, Direction::Outbound)
    ->build();
```

Inbound-only by default is the least surprising reading: an auth middleware should not silently
also run on the server's own `sampling/createMessage` calls.

### Both directions

`Direction::Outbound` attaches at `ClientGateway::suspend()`, which every outbound request
already funnels through. This is what makes the layer able to carry SEP-2624's client-feature
events, and it is cheap precisely because that choke point already exists.

### Relationship to the PSR-14 events

The events keep their place as **observation**; middleware becomes the supported way to **change
or stop** a message. Concretely: `RequestEvent::setRequest()`, and `ResponseEvent`/`ErrorEvent`'s
replacement setters, get deprecated in favour of middleware, while the events themselves stay for
metrics, audit and debugging. Pre-1.0 permits the deprecation; the alternative — two mutation
mechanisms with different ordering guarantees — is worse than either alone.

## Prerequisite: close the transport bypass

Constraint 2 is not merely awkward for this ADR; as far as can be traced from the code it is a
live defect, and the middleware layer must not inherit it. When an exchange suspends, the final
result is written by `handleFiberTermination()` in the transport rather than by
`Protocol::sendResponse()`, which means for **any request whose handler elicits, samples or
lists roots**:

- no `ResponseEvent` is dispatched; and
- `sendResponse()`'s JSON-encode fallback — which emits an internal-error frame when the result
  cannot be encoded — does not apply. The transport logs and writes nothing, so the client waits
  out its timeout instead of receiving an error.

Existing coverage does not contradict this: `ProtocolTest` asserts `ResponseEvent` only for the
path where the handler returns without suspending. **Confirm with a test first** — a handler that
elicits and then returns, asserting a `ResponseEvent` — and fix by routing the transports' final
write back through `Protocol`. Then events and middleware both hook one place, and `onResult`
can promise to run.

This ADR should not be implemented before that fix lands.

## What this deliberately does not offer

- **No `finally` across a suspension.** No timing a whole exchange, no transaction held open
  around a handler, no guaranteed cleanup in one frame. Constraints 1–3 make it unimplementable,
  and a layer that pretended otherwise would be worse than the events it replaces.
- **No notification middleware.** Inbound `handleNotification()` and outbound
  `ClientGateway::notify()` stay observation-only for now. SEP-2624's events do not cover
  notifications, and nothing else asks.
- **No middleware on the wire bytes.** That is the HTTP stack's job and it already exists. This
  layer starts at a decoded `Request`.
- **No reordering or priorities.** Registration order, and reverse for the result phase. If a
  priority system is ever needed, it can be added without changing the interface.

## Open questions

1. **Naming.** `Mcp\Server\Middleware\MiddlewareInterface` sits one `use` statement away from
   `Psr\Http\Server\MiddlewareInterface`, and files that touch both are exactly the confusing
   ones. `ProtocolMiddlewareInterface`, or a namespace that reads unambiguously at the import.
2. **Client-side registration.** `Client\Protocol` dispatches inbound server→client requests, so
   the layer applies symmetrically — but `Client\Builder` has no `addMiddleware()` and
   `ClientGateway` has no client-side counterpart. Same shape, or is the client's outbound side
   (its own `tools/call`) a different concern?
3. **Does `onResult` see a short-circuit from an outer middleware, or only a real result?** The
   sketch says it sees both, which is PSR-15's behaviour; the alternative is simpler to reason
   about but surprises anyone who expects the onion.
4. **How does a middleware carry state between phases** when they are in different processes
   (constraint 3)? Options: it does not, and cross-process state is the middleware's own problem
   via the session; or the context grows a serializable per-exchange bag alongside
   `requestState`.
5. **Error-phase distinction.** `onResult` receives `Response|Error` undifferentiated. A separate
   `onError` reads better but doubles the interface for a distinction `instanceof` already makes.

## Consequences

- `OAuthRequestMetaMiddleware` can stop decoding and re-encoding the JSON-RPC body: identity
  becomes an inbound middleware that annotates the decoded request.
- stdio gains every capability HTTP has at this layer, from one implementation.
- Rate limiting, quotas and audit become supported rather than inventive.
- SEP-2624's invoker role becomes implementable, and — per ADR 0002 — the extension axis for it
  is then one method returning middlewares, with a default.
- One mutation mechanism instead of two, once the event setters are deprecated.
- A bug fix lands first, with a test that should have existed already.

## Next step

Write the failing test for the bypass. It is small, it is independently valuable whatever happens
to this ADR, and it decides whether constraint 2 is as described.
