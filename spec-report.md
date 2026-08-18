# MCP 2026-07-28 — Gap Analysis and Status

**Branch:** `2026spec-findings`, branched from `feature/2026-07-28` @ `800ac39`
**Spec audited:** `../modelcontextprotocol` — `schema/2026-07-28/schema.ts` (3197 lines) and `docs/specification/2026-07-28/**`
**Upstream tracker:** `modelcontextprotocol/php-sdk` — 76 open issues, 33 carrying the `2026-07-28` label
**Audit date:** 2026-08-15 · **Last updated:** 2026-08-15

---

## 0. Status at a glance

The original audit found three structural holes and eight bugs. **All eight bugs and all three structural
holes are closed**, along with the extensions framework, subscriptions delivery, request-scoped
streaming, the caching policy, deprecations, docs and end-user examples. The Tasks extension is
carved out to its own PR (#428) and is not part of this branch. The **client** now
speaks the revision too, so both halves of the SDK are on 2026-07-28.

| | Before | After |
| --- | --- | --- |
| Modern conformance (`make conformance-draft-server`) | 138 passed / 3 failed | **151/151** |
| Handshake conformance (`make conformance-server`) | 39/39 | **80/80** |
| Server baseline entries | 3 | **0** |
| Modern client conformance (`make conformance-draft-client`) | not gated | **baseline clean** |
| Unit tests | 1222 | **1512** |
| Integration tests | 33 | **64** |
| Inspector snapshot tests | 97 | **103** |
| PHPStan | 7 pre-existing errors | 7 pre-existing errors |

Both sides now pass both revisions outright. The two server failures the branch had disclaimed turned out
to be fixture bugs, not SDK ones — a resource template echoing back its own `{id}` pattern instead of the
resolved URI, and a `json_schema_2020_12_tool` neither conformance server defined. The check counts jumped
because the runner is now pinned to a version that carries the 2026-07-28 scenarios and is run with
`--suite all` on both revisions.

**What is done** — §A lifecycle & transport · §B MRTR · §C headers & metadata · §D results & caching ·
§E errors & schema · §F subscriptions · §G1 extensions framework · §I1 deprecations · §I3 docs ·
the client side of all of it.

**What remains** — §3.1 MCP Apps ergonomics (an attribute and a scheme check) · §3.2 Authorization (mostly
blocked: there is no client-side OAuth for those rules to constrain yet) · §3.3 `anyOf` for a union of two
different array shapes · §3.4 conformance traceability. None is a MUST-level gap.
These are tracked in [§3](#3-remaining-work) with the same requirement/evidence/action shape as the original
audit. Everything else in this document is kept as the record of what was found and how it was closed.

---

## 1. What was closed

Each entry names the commit. Read the commit message for the reasoning; this table is the index.

### Bugs (§J of the original audit)

| # | Severity | Summary | Commit |
| --- | --- | --- | --- |
| B1 | High | `ClientGateway`'s six `supports*()` probes always returned `false` under the modern lifecycle — the session key they read is only written by `InitializeHandler`. Silent wrong answers, and it undermined the very capability guard MRTR asks handlers to write. | `781f1cb` |
| B2 | High | `resources/subscribe` / `resources/unsubscribe` still dispatched and answered `200 OK`, recording subscriptions nothing in that lifecycle reads. | `d104f51` |
| B3 | High | `Mcp-Name` compared to the body **without** Base64-sentinel decoding, so any non-ASCII resource URI or tool name was refused `-32020`. `decode()` existed but was wired only into the `Mcp-Param-*` path. | `ce804b9` |
| B4 | High | A missing `MCP-Protocol-Version` header was accepted, leaving the value intermediaries route on unenforced. | `c093a28` |
| B5 | Medium | `-32002` emitted where the revision forbids it; `prompts/get` and `completion/complete` used it for an unknown *name*, which it never meant; `tools/call` answered `-32601` for an unknown tool. | `4135c80` |
| B6 | Medium | A notification POST was dispatched as a request and answered with a JSON-RPC error carrying `"id": ""` — on the very path a client uses to tell a modern server from a legacy one. | `4aca43f` |
| B7 | Medium | `StatelessHttpTransport` took the whole body from one `read()`, which PSR-7 only fills *up to* the requested length; a chunked transfer truncated into `-32700`. | `fde1b8a` |
| B8 | Low | `-32021` was only reachable from `tools/call`; prompt and resource handlers absorbed the exception into `-32603`. | `4135c80` |

### Structural holes

| Area | What was missing | Commit |
| --- | --- | --- |
| §A5/§A6 | No request-scoped SSE stream, so `progress()` and `log()` reached for a fiber that was not there and came back as `-32603`. `io.modelcontextprotocol/logLevel` was parsed and discarded. | `8761e5a` |
| §F1–F3 | `subscriptions/listen` acknowledged, slept 30 s and closed. With `resources/subscribe` also gone, the revision had no server-push at all. | `81e7c04` |
| §G1 | `enableExtension()` took any string and advertised it; an extension could not add a method, because `MessageFactory` could not decode one. | `bfd7dcf` |
| §G2 | Tasks (SEP-2663) absent entirely — no schema, no store, no `tasks/*` surface. | carved out to PR #428 |

### Everything else

| § | Item | Commit |
| --- | --- | --- |
| B2 | `resources/read` could not answer with an `InputRequiredResult` | `bc894bd` |
| B3 | No guard against asking for an undeclared client capability | `1f2d392` |
| B4 | `InputContext` handed back raw arrays; `ClientGateway`'s handshake-era methods failed opaquely | `2f30c35`, `8761e5a` |
| B5 | MRTR retries were given caching hints their inputs cannot key | `bc894bd` |
| C4 | `x-mcp-header` inspected only top-level properties; no annotation validation; string-compared integers | `929e06d` |
| C5 | W3C trace context (`traceparent`/`tracestate`/`baggage`) not propagated | `8b202b3` |
| D2 | Caching hints frozen at `ttlMs: 0, cacheScope: private` with no way to change them | `50219dc` |
| E2 | Reserved error codes still emitted | `4135c80` |
| E4 | Unsupported `$schema` dialect reported as an internal fault; a union type silently lost a branch | `8a87215`, `3cf7aa2` |
| E5 | No `$ref` SSRF statement and no composition-DoS bound | `8a87215` |
| I1 | Roots / Sampling / Logging not marked deprecated | `eadd9fe` |
| I3 | Nothing in `docs/` described the modern lifecycle | `07fb337` |
| — | No end-user example of the revision | `9a7f3f0` |
| G2 | Tasks: schema, stores, `tasks/*` surface, capability gating | carved out to PR #428 |
| — | MCP Apps example unusable over HTTP; no snapshots pinning its `_meta` | `94b399a` |

### Notes worth carrying forward

- **`$ref` SSRF was already safe, but only by omission.** Opis registers no network resolver, so an
  external `$ref` failed as "unresolved reference". `SchemaComplexityGuard` now states the rule and tests
  it. The composition bound was a real hole: sixteen nested two-branch `anyOf`s took **9.0 s** and 65 536
  error objects; measured, `Validator::setMaxErrors()` bounds the *report*, not the walk, so the guard is
  structural and runs first. The same bomb written with `$defs` is a few hundred bytes, which is why the
  estimate resolves same-document `$ref`s rather than counting nodes.
- **Streaming is decided after the handler's first suspension.** Deciding earlier would have forced
  `-32021` and `-32602` onto an SSE frame under a `200`, breaking the statuses the spec fixes for them.
- **The notification bus needs shared storage under PHP-FPM.** In-memory delivery looked fine in unit tests
  and failed the conformance subscription checks, because the worker holding the stream and the worker
  publishing are different processes. `Psr16NotificationBus` is what makes those checks pass.
- **`Error::$id` is now nullable.** An error whose id could not be read omits the member instead of sending
  `"id": ""`. This also closes upstream #333 and the id half of #381.

---

## 2. Spec delta at a glance

`✅` implemented · `🟡` partial · `❌` absent.

| Area | Change | Status |
| --- | --- | --- |
| Lifecycle | `initialize` / `notifications/initialized` removed | ✅ |
| Lifecycle | `server/discover` — servers **MUST** implement | ✅ |
| Lifecycle | Per-request `_meta` (version, capabilities, clientInfo, logLevel) | ✅ |
| Lifecycle | `ping`, `logging/setLevel`, `roots/list_changed` removed | ✅ |
| Lifecycle | `resources/subscribe` / `resources/unsubscribe` removed | ✅ |
| Transport | `Mcp-Session-Id` removed; GET/DELETE → 405 | ✅ |
| Transport | Per-request SSE response stream for progress/logging | ✅ |
| Transport | Notification POST → `202 Accepted` | ✅ |
| Transport | `Last-Event-ID` / resumability removed | ✅ |
| Subscriptions | `subscriptions/listen` + acknowledgment + delivery | ✅ |
| MRTR | `InputRequiredResult`, `inputRequests`, `inputResponses`, `requestState` | ✅ |
| MRTR | Supported on `tools/call`, `prompts/get`, `resources/read` | ✅ |
| Results | `resultType` required on every result | ✅ |
| Results | `ttlMs` + `cacheScope` on the six cacheable methods | ✅ |
| Headers | `Mcp-Method` / `Mcp-Name` required, Base64 sentinel decoded | ✅ |
| Headers | `x-mcp-header` → `Mcp-Param-*`, nested and validated | ✅ |
| Observability | W3C trace context in `_meta` (SEP-414) | ✅ |
| Errors | `-32020` / `-32021` / `-32022`; `-32002` retired | ✅ |
| Schema | `outputSchema` / `structuredContent` widened (SEP-2106) | ✅ |
| Schema | `$ref` SSRF and composition bounds | ✅ |
| Schema | 2020-12 vocabulary in the generator | 🟡 §3.3 |
| Extensions | `extensions` capability + negotiation framework (SEP-2133) | ✅ |
| Extensions | Tasks (SEP-2663) | ✅ — shipped separately in PR #428 |
| Extensions | MCP Apps (SEP-1865) | ✅ (ergonomics outstanding, §3.1) |
| Deprecation | Roots / Sampling / Logging marked deprecated (SEP-2577) | ✅ |
| Auth | SEP-2351 / 837 / 2352 / 2468 / 2207 / 2350 | 🟡 §3.2 — mostly blocked on there being no OAuth client |

---

## 3. Remaining work

Ordered by the sequence a future session should take them in.

### 3.1 MCP Apps — ergonomics only, upstream #351

**Done.** The extension works end to end today and is now pinned: `enableExtension(new McpApps())`, a `ui://`
resource carrying `text/html;profile=mcp-app` and the `ui` marker, and a tool carrying `UiToolMeta` that
links to it. The example was also *broken over HTTP* — it was the only one without a session store, so every
request under `php -S` got "Session not found or has expired" — and is now covered by Inspector snapshots
that pin the `_meta` both halves travel in (`94b399a`). That closes the test half of #352.

**Remaining (small).** A `#[McpUiResource]` attribute, so an app resource can be declared the way every
other element can instead of through `addResource()` with a hand-written marker; and validation that a
resource declaring the app MIME type also uses the `ui://` scheme, and vice versa. Both are ergonomics —
nothing is unreachable without them.

### 3.2 Authorization — split differently from how the issue list reads

Audited rather than implemented, and the shape is not what the seven open issues suggest.

**The client-side items cannot be done yet.** #360 (validate `iss`), #361 (key credentials by issuer),
#363 (`offline_access`), #376 (`application_type` in DCR) and #377 (RFC 8414 suffix) are all rules about how
an OAuth *client* behaves — and this SDK has no client-side OAuth at all. `src/Client/` holds Builder,
Configuration, Handler, Protocol, State and Transport; there is no auth directory, and
`grep -rl 'oauth\|Bearer' src/Client/` returns nothing. That is what upstream #315–#325 are: eleven issues
building the OAuth client from scratch. The five `2026-07-28` items are constraints *on that work*, and each
should be folded into the issue that builds the piece it constrains, not attempted separately.

**#364 (SEP-2207, PRM must not advertise `offline_access` as required) has nothing to fix.**
`ProtectedResourceMetadata::$scopesSupported` is entirely operator-supplied; nothing in `src/` writes
`offline_access` anywhere. Pinned with a regression test (`ProtectedResourceMetadataTest`) so a future
default cannot quietly start advertising one. The remaining substance is guidance for operators, which
belongs in `docs/authorization.md`.

**#362 (SEP-2350, per-operation scopes in a 403) is half-present.** The mechanism exists:
`JwtTokenValidator::requireScopes()` produces a `403` with `insufficient_scope`, and
`AuthorizationMiddleware::buildAuthenticateHeader()` already emits `scope="…"` in the `WWW-Authenticate`
challenge alongside `resource_metadata` and `error` — which is what RFC 6750 §3.1 asks for. What is missing
is *per-operation*: nothing declares which scopes a given tool or resource needs, so `requireScopes()` can
only be called by the application with a set it works out itself. Closing it needs per-element scope
metadata, which is the same seam as upstream #159 (expose request-level `securitySchema` to handlers) and
should be designed with it.

**Undecided.** The changelog deprecates RFC 7591 DCR in favour of Client ID Metadata Documents
(`changelog.mdx:93-99`). No upstream issue tracks it, and `adr/0001` puts the authorization server itself out
of scope, so the SDK's position on CIMD needs an explicit decision.

---

### 3.3 JSON Schema 2020-12 generation — P1, upstream #356

**Done already:** an unsupported `$schema` dialect is reported as such (`8a87215`), and a union type no
longer loses a branch — in both places it was dropped (`3cf7aa2`, `555e962`). `string[]|int` now generates
`{"type": ["array","integer"], "items": …}`, which accepts either branch and refuses a float.

**Remaining.** A union of two *different* array shapes (`string[]|int[]`) still collapses to one `array`
with whichever `items` is inferred first, because a `type` array cannot carry per-branch keywords. `anyOf`
with a schema per branch is the 2020-12 answer for that case. `$defs` + `$ref` for repeated object shapes,
and extending `#[Schema]` so composition keywords are expressible without hand-writing the whole schema,
follow from the same work.

Related: #370 (`additionalProperties` unsupported) and #397 (phpstan/psalm number intervals).

---

### 3.4 Conformance traceability — P2, upstream #367, #368

`tests/Conformance/conformance-baseline-2026-07-28.yml` records no server-side failures at all. #367 asks
to wire SEP traceability files into the runner and surface per-SEP pass rates; #368 is the Tier 2 gap
analysis, for which this document is input.

---

## 4. Verification

Four conformance runs, one per (role × lifecycle). All four are clean against their baselines, and all
four run in CI — `.github/workflows/pipeline.yaml` matrixes each role over the two revisions.

```
make conformance-server         # handshake era, at /  — 80/80
make conformance-draft-server   # modern era, same /   — 151/151
make conformance-client         # handshake         — baseline clean (auth stack absent, §3.2)
make conformance-draft-client   # modern            — baseline clean (auth stack absent, §3.2)

vendor/bin/phpunit --testsuite=unit          # 1512
vendor/bin/phpunit --testsuite=integration   # 64, boots the examples over real HTTP
vendor/bin/phpunit --testsuite=inspector     # 103 (7 skipped); handshake-era examples only
vendor/bin/phpstan --memory-limit=-1         # 7 pre-existing errors, all alreadyNarrowedType under PHP 8.5
vendor/bin/php-cs-fixer fix
```

The conformance runner is pinned to the same version CI uses. The 2026-07-28 scenarios ship on the `alpha`
dist-tag only — `latest` (0.1.x) carries none of them — so `conformance-weekly.yaml` tracks `latest` for
the dated revision and `alpha` for the draft one, which is what keeps the pin from going stale.

**The Inspector cannot reach a modern-lifecycle server.** It opens with `initialize`, which this revision
removed, so `tests/Inspector/` covers the handshake-era examples only. The
`stateless-lifecycle` example is verified instead by two integration tests, one per direction:
`StatelessLifecycleTest` drives it with hand-built HTTP the way a conforming client would — discovery, a
tool call, both MRTR rounds, a tampered `requestState`, and the response stream carrying interleaved
progress and log notifications — and `StatelessClientTest` drives it with the SDK's own client, which is
what proves that client is conforming.
