# 0002 — Segregate `ExtensionInterface` along the axes extensions actually use

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

A **thin base** that every extension can honestly implement, plus **opt-in interfaces, one per
integration axis**, each introduced because an accepted extension forces it. The
`ArgumentProvidingExtensionInterface` split already on `feat-ext-tasks` is this pattern; the
sketch generalises it rather than inventing it.

### Base

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
}
```

That is the whole of an auth extension. `AbstractExtension` disappears — there is nothing left
for it to stub out.

### The axes

Each is `extends ExtensionInterface`, each is checked with `instanceof` where it is consumed.

```php
/** Forced by: Tasks, Skills. Registers message classes with the MessageFactory. */
interface MessageProvidingExtensionInterface extends ExtensionInterface
{
    /** @return list<class-string<Request>|class-string<Notification>> */
    public function getMessages(): array;
}

/** Forced by: Tasks, Skills. Built at build() time, with the SDK's services in hand. */
interface HandlerProvidingExtensionInterface extends MessageProvidingExtensionInterface
{
    /** @return iterable<RequestHandlerInterface<ResultInterface>|NotificationHandlerInterface> */
    public function getHandlers(ExtensionContext $context): iterable;
}

/** Forced by: Skills. Contributes tools/resources/templates/prompts and server instructions. */
interface ElementProvidingExtensionInterface extends ExtensionInterface
{
    public function registerElements(ElementRegistrar $elements): void;

    /** Appended to the server's `instructions`, e.g. the Skills pointer. */
    public function getInstructions(): ?string;
}

/** Forced by: Tasks. Unchanged in spirit from feat-ext-tasks; now receives the context. */
interface ArgumentProvidingExtensionInterface extends ExtensionInterface
{
    /** @return array<class-string, callable(SessionInterface, Request): object> */
    public function getArgumentProviders(ExtensionContext $context): array;
}

/**
 * Forced by: Tasks. Declares the result shapes a user handler may return instead of the
 * method's own result, so core handlers pass them through by declaration rather than by a
 * hardcoded `instanceof CallToolResult` check.
 */
interface ResultProvidingExtensionInterface extends ExtensionInterface
{
    /** @return list<class-string<ResultInterface>> */
    public function getResultTypes(): array;
}

/**
 * Forced by: Skills (`directoryRead` must match what is served; the extension requires the
 * `resources` capability), Auth (the settings announce a transport the build may not have).
 * Replaces a bespoke requires()/dependency DSL with one throw at build time.
 */
interface SelfCheckingExtensionInterface extends ExtensionInterface
{
    /** @throws LogicException when the build cannot honour what getSettings() announces */
    public function check(ExtensionContext $context): void;
}

/**
 * Forced by: Apps, Skills. An extension has two namespaces and today's interface models one:
 * `io.modelcontextprotocol/skills` is the capability key, `io.modelcontextprotocol.skills/`
 * is the `_meta` prefix, and Apps uses a bare `ui`. Make the second one explicit.
 */
interface MetaProvidingExtensionInterface extends ExtensionInterface
{
    public function getMetaPrefix(): string;
}
```

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

## What each extension implements

| | base | Message | Handler | Element | Argument | Result | SelfCheck | Meta |
|---|---|---|---|---|---|---|---|---|
| Auth (both) | ● | | | | | | ● | |
| Apps | ● | | | | | | | ● |
| Tasks | ● | ● | ● | | ● | ● | ● | |
| Skills | ● | ● | ● | ● | | | ● | ● |

Every cell that is empty today costs a core patch or a hand-wired workaround. Every cell that is
filled is forced by an extension that has been accepted, not by speculation.

## What this deliberately does not add

- **No request/response middleware or interceptor chain.** Tasks needs result *pass-through*,
  which `ResultProviding…` gives declaratively; nothing on the agenda needs to rewrite another
  extension's traffic. `experimental-ext-interceptors` may change that; wait for it.
- **No dependency DSL** between extensions or on protocol revisions. `SelfChecking…` covers the
  one real case (Skills → `resources`) with a throw, not a graph.
- **No extension versioning API.** SEP-2133 says: flags inside the settings object, and a new
  identifier for a breaking change. Both already work.
- **No transport-configuring interface.** Auth's behaviour stays in `Client\Auth\*` and
  `HttpTransport`; the extension only *announces* it and `check()` makes sure the announcement
  is true. Letting an extension reach into the transport is a much larger door than auth needs.
- **No per-session enablement.** ext-apps suggests registering tool variants per client
  capability; the registry is fixed at `build()`. Worth a separate ADR, not this one.

## Migration

The SDK is pre-1.0 (see `docs/deprecation-policy.md`), so this is a `[BC Break]` changelog entry
rather than a deprecation cycle. Affected: `McpApps` (drop `AbstractExtension`, make settings
side-aware), the two test fixtures, and `feat-ext-tasks`, which is the natural first consumer —
it would *shrink*, losing its core patches to `CallToolHandler`, `ReferenceHandler`,
`SchemaGenerator` and `Protocol`.

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
4. **Does `ElementProviding…` register through the builder or through a loader?** A narrow
   `ElementRegistrar` façade keeps extensions off the full `Builder` API, but it is a second
   registration path to keep in sync.
5. **Is `resources/directory/read` (Skills) an extension method or a core one?** If extensions
   may serve methods in core namespaces, the namespace guard above needs an explicit opt-in verb
   rather than a flat throw.

## Consequences

- An auth extension becomes two methods instead of four, two of them returning `[]` to say
  "I am not that kind of extension".
- Tasks stops being a special case in core: no `instanceof CallToolResult` carve-out, no named
  exception catch, no `injectedTypes` plumbed by hand.
- Skills becomes implementable as a first-class `->enableExtension(new SkillsExtension($dir))`
  rather than a documentation page telling users to hand-wire resources.
- Clients can finally implement the half of an extension they are responsible for.

## Next step

Spike **Skills** against this sketch before freezing anything. It is the only extension that
exercises element contribution, a core-namespace method, pagination-in-a-handler and a declared
dependency at once — the way Tasks exercised result transformation and argument injection.
