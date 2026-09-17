# 0002 — Widen `ExtensionInterface` to the axes extensions actually use

- Status: **Proposed** (sketch for discussion — nothing implemented)
- Date: 2026-09-17

## Context

`Mcp\Schema\Extension\ExtensionInterface` encodes one model of what an extension is: **a
namespace of new JSON-RPC methods plus a capability payload**, wired at build time, on the
server. Four methods — `getId()`, `getCapabilities()`, `getMessages()`, `getRequestHandlers()` —
and a docblock that calls implementations "typically zero-config" and says "the same extension
object can be enabled on a server and on a client".

Cross-checking that against the extensions on the agenda shows the model fits roughly one of
them, partially.

| Extension | What it actually is |
|---|---|
| **Apps** (`io.modelcontextprotocol/ui`) | `_meta` on existing primitives + a `postMessage` side channel. **Zero** RPC methods. |
| **Auth** (`oauth-client-credentials`, `enterprise-managed-authorization`) | Transport behaviour (bearer token, JWKS validation, scopes). **Zero** RPC methods; the capability key is the entire protocol surface. |
| **Tasks** (SEP-2663) | New `tasks/*` methods **plus** a transformation of the results of `tools/call`, `prompts/get`, `resources/read`, **plus** a context object injected into user handlers, **plus** an error mapping. |
| **Skills** (SEP-2640) | New `skills/*` methods **plus** a method in a *core* namespace (`resources/directory/read`) **plus** contribution of core primitives (skills are served as resources) **plus** a config-dependent settings object that declares a dependency on the `resources` capability. |

The evidence that the current shape does not carry this load is already in the tree:

- **Tasks needed a second interface and seven core edits.** `feat-ext-tasks` adds
  `ArgumentProvidingExtensionInterface`, `ResultType::Task`, a relaxation of
  `CallToolHandler`/`GetPromptHandler`/`ReadResourceHandler` to let a foreign result through,
  `injectedTypes` in `SchemaGenerator`, `argumentProviders` in `ReferenceHandler`, and a
  `catch (MissingRequiredClientCapabilityException)` in `Protocol`. None of that is expressible
  through the contract.
- **Handlers are built too early.** `Builder::enableExtension()` drains
  `getRequestHandlers()` at call time (`src/Server/Builder.php:454`), long before `build()`, so
  an extension handler can never receive the logger, `Configuration` (and its
  `paginationLimit`), the registry, the session manager or the container. `skills/list` is
  paginated; it would have to reimplement cursor handling from scratch.
- **The client half does not exist.** `Client\Builder::enableExtension()` reads
  `getCapabilities()` and drops `getMessages()` and `getRequestHandlers()`
  (`src/Client/Builder.php:104`), and `Client\Protocol` hardcodes `MessageFactory::make()`
  (`src/Client/Protocol.php:97`), so a client cannot decode or serve an extension-defined
  server→client message at all. `Client\Protocol::initialize()` keeps `protocolVersion`,
  `serverInfo` and `instructions` and **discards `$initResult->capabilities`**
  (`src/Client/Protocol.php:225`), so there is no client-side mirror of
  `ClientGateway::supportsExtension()` — the SDK equips only one of the two sides SEP-2133
  asks to degrade gracefully.
- **One object cannot serve both sides.** In ext-apps, `mimeTypes` is a client-only REQUIRED
  setting and the server's settings object is `{}` in every example; `McpApps::getCapabilities()`
  returns the client shape unconditionally, so a PHP *server* advertises a client-shaped payload.
- **Nothing guards the core method namespace.** The collision check at
  `src/Server/Builder.php:444` compares extension against extension only. An extension claiming
  a core method gets its message class silently ignored (`MessageFactory` resolves core-first)
  while its handler is dispatched *ahead* of the core one (`src/Server/Builder.php:1086`).

SEP-2133 explicitly leaves schema modification, dependencies and profiles unspecified, and
grants SDKs full autonomy over "extension points, plugin systems, or any other mechanism".
So these contracts are ours to invent — and ours to keep small.

## Sketch

**One interface** that catalogues what an extension can hook into, with **`AbstractExtension`
carrying a default for every axis but identity and settings**. Each axis is on the interface
because an accepted extension forces it; each default is there so no extension pays for an axis
it does not use.

`ArgumentProvidingExtensionInterface` on `feat-ext-tasks` is the first of these axes, discovered
under pressure and split out because there was nowhere else to put it. This folds it back in.

### One interface, with `AbstractExtension` carrying the optionality

```php
namespace Mcp\Schema\Extension;

/** Which side of the connection is announcing. */
enum Peer
{
    case Server;
    case Client;
}

interface ExtensionInterface
{
    public function getId(): ExtensionIdentifier;

    /**
     * The settings object announced under `capabilities.extensions[<id>]`.
     *
     * Side-aware, because the spec's are: MCP Apps requires `mimeTypes` from a
     * client and defines nothing for a server.
     *
     * @return array<string, mixed>
     */
    public function getSettings(Peer $peer): array;

    /**
     * Message classes this extension defines, registered with the MessageFactory.
     *
     * Forced by: Tasks, Skills.
     *
     * @return list<class-string<Request>|class-string<Notification>>
     */
    public function getMessages(): array;

    /**
     * The handlers serving those methods, built with the SDK's services in hand.
     *
     * Forced by: Tasks, Skills. Today's equivalent is drained inside
     * enableExtension(), so a handler can never see the logger, the
     * Configuration (and its paginationLimit), the registry or the container.
     *
     * @return iterable<RequestHandlerInterface<ResultInterface>|NotificationHandlerInterface>
     */
    public function getHandlers(ExtensionContext $context): iterable;

    /**
     * Tools, resources, templates and prompts this extension contributes.
     *
     * Forced by: Skills, whose skills are served as resources. Apps stops
     * needing a documentation page that tells users to hand-wire them.
     */
    public function registerElements(ElementRegistrar $elements): void;

    /**
     * Appended to the server's `instructions` — e.g. the Skills pointer.
     *
     * Forced by: Skills.
     */
    public function getInstructions(): ?string;

    /**
     * Builders for types this extension injects into tool, prompt and resource
     * handlers, the way the SDK injects a RequestContext.
     *
     * Forced by: Tasks (TaskContext). Same shape as
     * ArgumentProvidingExtensionInterface on feat-ext-tasks, folded in here.
     *
     * @return array<class-string, callable(SessionInterface, Request): object>
     */
    public function getArgumentProviders(ExtensionContext $context): array;

    /**
     * Result shapes a user handler may return instead of the method's own result.
     *
     * Forced by: Tasks. Lets core handlers pass a foreign result through by
     * declaration rather than by a hardcoded `instanceof CallToolResult` check.
     *
     * @return list<class-string<ResultInterface>>
     */
    public function getResultTypes(): array;

    /**
     * Assert at build time that the build can honour what getSettings() announces.
     *
     * Forced by: Skills (`directoryRead` must match what is served; the
     * extension requires the `resources` capability), Auth (the settings
     * announce a transport the build may not have), Apps (a tool's
     * `_meta.ui.resourceUri` must resolve to a registered `ui://` resource).
     * Replaces a bespoke requires()/dependency DSL with one throw.
     *
     * Caveat: `$context->registry` is null when element loading is deferred —
     * reading it would force the load that detectCapabilities() is careful to
     * avoid. A check that needs the registry is best-effort, and must skip
     * rather than throw when it is absent.
     *
     * @throws LogicException when the announcement cannot be honoured
     */
    public function check(ExtensionContext $context): void;
}
```

`AbstractExtension` is what makes the seven methods bearable — and it is the same pattern
`BaseTransport` already uses in this SDK ("a skeletal implementation … to minimize the effort
required to implement this interface", with empty `initialize()`/`close()` for the optional
lifecycle hooks):

```php
/**
 * Provides a skeletal implementation of ExtensionInterface to minimize the effort
 * required to implement it: every axis but identity and settings defaults to
 * "this extension is not that kind of extension".
 */
abstract class AbstractExtension implements ExtensionInterface
{
    public function getMessages(): array
    {
        return [];
    }

    public function getHandlers(ExtensionContext $context): iterable
    {
        return [];
    }

    public function registerElements(ElementRegistrar $elements): void
    {
    }

    public function getInstructions(): ?string
    {
        return null;
    }

    public function getArgumentProviders(ExtensionContext $context): array
    {
        return [];
    }

    public function getResultTypes(): array
    {
        return [];
    }

    public function check(ExtensionContext $context): void
    {
    }
}
```

An auth extension is then genuinely two methods:

```php
final class OAuthClientCredentials extends AbstractExtension
{
    public function getId(): ExtensionIdentifier
    {
        return new ExtensionIdentifier('io.modelcontextprotocol/oauth-client-credentials');
    }

    public function getSettings(Peer $peer): array
    {
        return [];
    }

    // …plus check(), the one axis it does use, if the transport must be verified.
}
```

Note what this changes about `AbstractExtension` itself: not the class, but its justification.
Its current docblock scopes it to "an extension that only announces a capability and adds no RPC
methods of its own — the common case", which is a claim about extensions that the agenda has
already falsified. Reframed as the default carrier for every optional axis, it stops being a
shortcut for trivial extensions and becomes the supported base for all of them.

### The context

```php
namespace Mcp\Schema\Extension;

final class ExtensionContext
{
    public function __construct(
        public readonly Peer $peer,
        public readonly LoggerInterface $logger,
        public readonly Configuration $configuration,   // paginationLimit, protocolVersion, …
        public readonly ?ContainerInterface $container = null,
        public readonly ?RegistryInterface $registry = null,   // server side only
    ) {
    }
}
```

### Error mapping, without core knowing the extension

Today `Protocol` catches `MissingRequiredClientCapabilityException` by name. Invert it, so an
extension maps its own exceptions and core catches one thing:

```php
namespace Mcp\Exception;

interface JsonRpcErrorProvidingInterface extends \Throwable
{
    public function toError(string|int $id): Error;
}
```

### Posture belongs to the deployment, not the extension

Whether an extension is mandatory (reject a peer that did not declare it) or optional (degrade)
is a property of *this* server, not of the extension. So it is a builder verb, not an interface
method:

```php
$server = Server::builder()
    ->enableExtension(new SkillsExtension($dir))       // degrade if the peer lacks it
    ->requireExtension(new OAuthClientCredentials())   // refuse the peer that lacks it
    ->build();
```

### Client parity

The same interfaces, honoured symmetrically:

- `Client\Builder::enableExtension()` reads `MessageProviding…` and `HandlerProviding…`.
- `Client\Protocol` accepts a `MessageFactory` (it currently hardcodes `MessageFactory::make()`),
  so extension messages reach it.
- `Client\Protocol::initialize()`/`readDiscovery()` keep the peer's capabilities, and `Client`
  grows `getServerCapabilities()` and `supportsExtension(ExtensionIdentifier|string)` — the
  mirror of `ClientGateway::supportsExtension()`.

### Namespace guard

`enableExtension()` throws when an extension message claims a core method name. Shadowing a core
method stays possible, deliberately and loudly, through `addRequestHandler()` — which is already
documented as a low-level escape hatch. An extension should not get it silently.

## What each extension overrides

Everything not listed is inherited from `AbstractExtension`.

| | getMessages | getHandlers | registerElements | getInstructions | getArgumentProviders | getResultTypes | check |
|---|---|---|---|---|---|---|---|
| Auth (both) | | | | | | | ● |
| Apps | | | | | | | ● |
| Tasks | ● | ● | | | ● | ● | ● |
| Skills | ● | ● | ● | ● | | | ● |

Every filled cell is forced by an extension that has been accepted, not by speculation, and
each one costs a core patch or a hand-wired workaround today. Every empty cell is a method the
implementer never writes.

## Considered and dropped

**Interface segregation — one opt-in interface per axis**, consumed by `instanceof` in the
builder. This was the first shape of this sketch, on the reasoning that a seven-method interface
makes an auth extension answer five questions it does not have, returning `[]` to say "I am not
that kind of extension".

That objection evaporates once `AbstractExtension` carries the defaults: auth writes two methods
either way, and writes them without the implementer first having to learn which of seven
interfaces exist. The rest of the comparison then goes the other way too:

- **PHP has no default interface methods.** Segregation is the workaround for that gap; a
  skeletal abstract base is the direct expression of it, and the one this SDK already uses —
  `BaseTransport` is exactly this, down to the empty `initialize()`/`close()`.
- **One interface is the catalogue.** Seven interfaces are only discoverable if you already know
  to look for them; `ExtensionInterface` read top to bottom tells an author everything an
  extension can do.
- **No `instanceof` ladder.** `enableExtension()` and `build()` call the methods straight
  through instead of branching on seven type checks, each of which is a place to forget one.
- **An empty return is never ambiguous here.** No axis has a meaningful difference between
  "absent" and "empty": no messages, no handlers, no elements, null instructions, no argument
  providers, no result types, a no-op check. Where that distinction does not exist, `instanceof`
  buys nothing over a default.

Two costs come with it, and are accepted:

1. **The abstract class becomes the real API.** Anyone writing `implements ExtensionInterface`
   without extending `AbstractExtension` — a class that already has a parent, say — implements
   all seven. That is the standard trade of this pattern, and `BaseTransport` already lives with
   it.
2. **The interface is now cheap to grow.** Adding an axis costs one method and one default, so
   nothing pushes back on adding them. The discipline that segregation enforced structurally has
   to become a rule instead: *an axis is added only when an accepted extension forces it, and it
   must have a sane default.* The "does not add" list below is that rule's current output.

**A `_meta` namespace interface** (`getMetaPrefix(): string`). The review that produced this ADR
listed "an extension has two namespaces and the interface models one" as a gap:
`io.modelcontextprotocol/skills` is the capability key while `io.modelcontextprotocol.skills/`
is the `_meta` prefix, and Apps uses a bare `ui`. That observation is correct; the interface
that follows from it is not.

`_meta` is an open, untyped bag. It is set at registration (`addTool(..., meta: [...])`, or the
`meta:` argument of the attributes), carried verbatim on `Schema\Tool::$meta` and friends,
serialised as-is, and read in whatever handler cares. It is deliberately forward-compatible: a
server must be able to pass through `_meta` for extensions this SDK has never heard of. So there
is no point in the pipeline where core could consume a prefix string:

- It cannot *validate* keys against enabled extensions without breaking that pass-through.
- It cannot *namespace* keys on the author's behalf — Apps' key is `ui`, not a prefix at all.
- It cannot *deconflict*, because reverse-DNS prefixes already cannot collide.

A method no consumer calls is a class constant with an interface wrapped around it. `McpApps`
already has the better version: `EXTENSION_ID`, `MIME_TYPE`, `URI_SCHEME` constants plus typed
DTOs (`UiToolMeta`, `UiResourceContentMeta`) that produce the payload. That is class design, and
it needs no contract.

What was real underneath the observation splits in two, and neither is an axis:

1. **Cross-reference validation** — that a tool's `_meta.ui.resourceUri` actually resolves to a
   registered `ui://` resource. That is build-time validation with the registry in hand, so it
   belongs to `SelfCheckingExtensionInterface` (above), where Apps now claims its one cell.
2. **A docblock correction.** `ExtensionIdentifier`'s class docblock calls the identifier "a
   `_meta` key with a mandatory vendor prefix", conflating the capability key with the `_meta`
   prefix. That is a wrong sentence to fix, not an interface to add.

## What this deliberately does not add

- **No middleware axis.** Not because middleware is unwanted — see the section below, it is the
  most defensible of the deferred items — but because the layer an extension would contribute to
  does not exist yet, and the one that does cannot be reached from `enableExtension()`.
- **No dependency DSL** between extensions or on protocol revisions. `SelfChecking…` covers the
  one real case (Skills → `resources`) with a throw, not a graph.
- **No extension versioning API.** SEP-2133 says: flags inside the settings object, and a new
  identifier for a breaking change. Both already work.
- **No transport-configuring interface.** Auth's behaviour stays in `Client\Auth\*` and
  `HttpTransport`; the extension only *announces* it and `check()` makes sure the announcement
  is true. Letting an extension reach into the transport is a much larger door than auth needs.
- **No per-session enablement.** ext-apps suggests registering tool variants per client
  capability; the registry is fixed at `build()`. Worth a separate ADR, not this one.

### On middleware specifically

The SDK has two-and-a-half middleware stories and the gap between them is where auth is
currently living:

| Layer | State | Owned by |
|---|---|---|
| HTTP | PSR-15, eight middlewares, `defaultMiddleware()` / `[]` to disable | the **transport constructor** |
| Protocol (decoded `Request` → `Response`) | absent; PSR-14 events *mutate* (`RequestEvent::setRequest()`, `ErrorEvent`'s replacement) but cannot short-circuit or wrap a call | — |
| stdio | nothing | — |

`OAuthRequestMetaMiddleware` is the proof of the missing middle. To get HTTP-layer identity into
`RequestContext`, it decodes the JSON-RPC body, injects `_meta.oauth` into each message
(batch-aware), and re-encodes it. A protocol-level concern, implemented by rewriting bytes one
layer down, because there is no layer in between to put it in.

So a `getMiddleware()` axis would be wrong three times over:

1. **The builder cannot install it.** Middleware goes to the transport constructor; extensions go
   to the builder, which never sees the transport. Honouring the axis means inverting that wiring
   — a larger change than the whole of this ADR.
2. **PSR-15 is HTTP-only.** An extension declaring middleware would be silently inert over stdio.
   An axis whose contract depends on the transport is worse than no axis.
3. **It would point at a layer that does not exist.** What auth wants is protocol middleware, and
   adding the axis before the layer just relocates the gap.

And SEP-2624 is *not* the thing to wait for, contrary to what an earlier draft of this ADR said.
Its interceptors are **remote interceptor servers**, discovered and invoked over JSON-RPC by a
gateway — a distributed governance chain, not an in-process pipeline. It neither supplies nor
implies a local middleware layer.

One constraint for that ADR, found by asking what SEP-2624 would need if this SDK ever played
*invoker* (the SEP says SDKs are expected to ship the chain orchestration): its event list
includes `sampling/createMessage`, `elicitation/create` and `roots/list`. Those are **outbound**
server→client requests, issued through `ClientGateway`, not inbound dispatch. A layer built only
as a decorator around inbound `Request → Response` handling would cover `tools/call` and
`resources/read` and structurally miss a third of the SEP's own events. **The layer has to wrap
both directions.** Note this says nothing about stdio: hosting interceptors
(`interceptors/list`, `interceptor/invoke`) needs no middleware at all on any transport — it is
new methods and a new primitive kind, which the interface above already covers.

A protocol middleware layer is therefore worth its own ADR — [0003](0003-protocol-middleware.md)
drafts it — and is valuable independently of extensions: short-circuiting and wrapping are things users want directly, stdio would reach
parity with HTTP, and `OAuthRequestMetaMiddleware` could stop rewriting JSON. If that layer
lands, the extension axis is one method and one default — exactly the pattern above. Until then
the governance rule applies and says wait: **no accepted extension forces it.** Auth is served
by PSR-15 today, and interceptors are both experimental and remote.

## Migration

The SDK is pre-1.0 (see `docs/deprecation-policy.md`), so this is a `[BC Break]` changelog entry
rather than a deprecation cycle. `AbstractExtension` survives with a wider job and four more
defaults, so anything already extending it keeps working past the `getSettings(Peer)` rename.
Affected: `McpApps` (side-aware settings, plus a `check()` for the `resourceUri` cross-check),
the two test fixtures, and `feat-ext-tasks`, which is the natural first consumer — it would
*shrink*, folding `ArgumentProvidingExtensionInterface` back into the base and losing its core
patches to `CallToolHandler`, `ReferenceHandler`, `SchemaGenerator` and `Protocol`.

## Open questions

1. **One `ExtensionContext` or two?** A single class with a nullable server-only `$registry` is
   simpler; `Server\ExtensionContext` + `Client\ExtensionContext` is honest but doubles the
   surface for extensions that live on both sides.
2. **Where does this namespace live?** `Schema\Extension` types against
   `Mcp\Server\Handler\Request\RequestHandlerInterface` today — schema depending on server is a
   layering smell, and the client half has no home. Candidate: `Mcp\Extension\*`, with
   `Schema\Extension` keeping only `ExtensionIdentifier`.
3. **`getSettings()` vs `getCapabilities()`.** The spec calls the payload a *settings object*;
   renaming aligns the vocabulary and makes the side-aware signature a clean break point. Costs
   a rename in every implementation.
4. **Does `registerElements()` go through the builder or a loader?** A narrow `ElementRegistrar`
   façade keeps extensions off the full `Builder` API, but it is a second registration path to
   keep in sync.
5. **Do the server-only axes belong on an interface clients implement?** `registerElements()`,
   `getArgumentProviders()` and `getResultTypes()` are server concepts, and a client-only
   extension now inherits all three. Passing `Peer` makes it coherent, but it does put
   server-namespace types on the interface every extension implements — which is question 2
   again, from the other side. This is the one thing segregation bought that defaults do not.
6. **Is `resources/directory/read` (Skills) an extension method or a core one?** If extensions
   may serve methods in core namespaces, the namespace guard above needs an explicit opt-in verb
   rather than a flat throw.

## Consequences

- An auth extension is two methods and a `check()`; the five axes it has no use for are
  inherited, not restated.
- `AbstractExtension` stops being a shortcut for trivial extensions and becomes the supported
  base for all of them — the same role `BaseTransport` plays for transports.
- Tasks stops being a special case in core: no `instanceof CallToolResult` carve-out, no named
  exception catch, no `injectedTypes` plumbed by hand.
- Skills becomes implementable as a first-class `->enableExtension(new SkillsExtension($dir))`
  rather than a documentation page telling users to hand-wire resources.
- Clients can finally implement the half of an extension they are responsible for.

## Next step

Spike **Skills** against this sketch before freezing anything. It is the only extension that
exercises element contribution, a core-namespace method, pagination-in-a-handler and a declared
dependency at once — the way Tasks exercised result transformation and argument injection.
