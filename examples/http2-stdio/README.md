# HTTP/2 over STDIO

A standalone demo: a PHP process spawns a child and speaks **plain HTTP/2 (h2c) over its
STDIN/STDOUT pipes** — no port, no socket, no TLS. Headers, status codes, streaming bodies and
stream multiplexing all work exactly as they do over TCP.

The point: STDIO is a transport, HTTP is a protocol, and the two are usually bundled together only
by habit. MCP's STDIO transport today is line-delimited JSON-RPC — no headers, one message at a
time — while everything header-shaped (session ids, protocol version negotiation, `Accept`,
resumable SSE streams) lives in the Streamable HTTP transport and needs a listening socket. This
example shows you can have the second over the first.

This is an experiment, not part of the SDK and not a transport the MCP specification defines.

## Run it

The example has its own dependencies (`amphp/http-client`, `amphp/http-server`, `amphp/process`),
so it installs separately from the SDK:

```bash
cd examples/http2-stdio
composer install
php client.php
```

To see the actual HTTP/2 frames as they cross the pipe:

```bash
H2_TRACE=1 php client.php
```

```
[wire] DEBUG   -> PREFACE
[wire] DEBUG   -> SETTINGS      stream=0 length=24 flags=0x00
[wire] DEBUG   <- SETTINGS      stream=0 length=24 flags=0x00
[wire] DEBUG   -> HEADERS       stream=3 length=73 flags=0x05
[wire] DEBUG   -> HEADERS       stream=5 length=19 flags=0x04
[wire] DEBUG   -> HEADERS       stream=7 length=19 flags=0x04
```

## What the demo shows

`client.php` spawns `server.php` and then runs through a small MCP-shaped conversation:

| Step | What it proves |
| --- | --- |
| `POST /mcp` with `initialize` | Response **headers** over a pipe: the session id comes back in `Mcp-Session-Id`, not in the payload |
| `GET /mcp` held open | A long-lived **server-to-client SSE stream**, the way the Streamable HTTP transport pushes notifications |
| Three calls dispatched at once | **Multiplexing**: a slow streaming `tools/call` does not block the two quick ones — with a line protocol they would queue behind it |
| `tools/call crunch` | One response that **streams**: progress notifications first, result last, `content-type: text/event-stream` |
| `notifications/initialized` | **`202 Accepted`** with no body — a notification needs no answer, and HTTP already has a word for that |
| `GET /nope`, `DELETE /mcp` | The rest of the contract: **`404`**, **`204`**, `Accept` negotiation answered with `406` |

Sample run (timings are relative, note how the streams interleave):

```
Concurrency: one pipe, four HTTP/2 streams at once
--------------------------------------------------
     52 ms  GET /mcp (open stream)     HTTP/2 200 OK  [content-type: text/event-stream]
     53 ms  tools/call crunch          HTTP/2 200 OK  [content-type: text/event-stream]
     53 ms  tools/call add             HTTP/2 200 OK  [content-type: application/json]
     53 ms                             <- {"jsonrpc":"2.0","id":3,"result":{...,"text":"42"}}
     53 ms  tools/list                 HTTP/2 200 OK  [content-type: application/json]
    303 ms                             <- progress 1/4
    453 ms                             <- notifications/message: scanning the index
    554 ms                             <- progress 2/4
   1055 ms                             <- result: crunched 4 chunks
```

## How it works

Neither the Amp HTTP client nor the Amp HTTP server cares whether its bytes come from a socket:

* the client needs an `Amp\Socket\Socket` to build an `Http2Connection` on,
* the server needs a readable plus a writable stream for `Http2Driver::handleClient()`.

A child process already owns two pipes, so one adapter is all that is missing.

| File | Role |
| --- | --- |
| [`src/StdioSocket.php`](src/StdioSocket.php) | The adapter: presents a readable/writable pipe pair as an Amp socket, TLS methods refused |
| [`src/SingleConnectionPool.php`](src/SingleConnectionPool.php) | Routes every request to the one connection that runs over the pipes, instead of dialing host:port |
| [`client.php`](client.php) | Spawns the child, sends the h2c preface, drives the conversation |
| [`server.php`](server.php) | `Http2Driver` reading STDIN and writing STDOUT, nothing listening anywhere |
| [`src/McpEndpointHandler.php`](src/McpEndpointHandler.php) | A miniature Streamable HTTP endpoint: sessions, `Accept` checks, SSE responses |
| [`src/FrameTracingSocket.php`](src/FrameTracingSocket.php) | Optional `H2_TRACE=1` decorator naming each frame |
| [`src/EventStream.php`](src/EventStream.php) | Reads `text/event-stream` bodies as they arrive |
| [`src/StderrLogger.php`](src/StderrLogger.php) | Logs to STDERR, because STDOUT carries frames |

Two details make it work:

1. **STDOUT must stay clean.** It carries HTTP/2 frames, so a stray `echo`, warning or
   `var_dump()` corrupts the connection. `server.php` sets `display_errors=stderr` and logs
   through STDERR — the same discipline the SDK's STDIO transport already requires.
2. **No TLS means no ALPN**, so the connection uses h2c with prior knowledge: the client simply
   sends the HTTP/2 preface and both sides exchange `SETTINGS`. Over a pipe between a parent and
   its own child there is nothing to encrypt and nobody to negotiate with.

Closing the pipes ends the connection: the child sees EOF, the driver shuts down, the process
exits — the same lifecycle as a client disconnecting from a socket.

## What you get for free

Everything HTTP/2 does on a socket keeps working on the pipe, which is the interesting part:

* **Headers** — session ids, protocol versions, content negotiation, auth if it ever made sense here.
* **Multiplexing** — concurrent requests and long-lived server streams share one pipe, answered out
  of order. A line-delimited protocol has to invent this itself.
* **Flow control** — HTTP/2 `WINDOW_UPDATE` frames apply backpressure per stream, so one huge
  response cannot starve the rest of the connection.
* **Status codes** — `202`, `404`, `406`, `204` say what a JSON-RPC error object has to spell out.

## What it does not do

* No SDK integration: this deliberately builds the HTTP layer by hand rather than running
  `Mcp\Server` on top, because the transport underneath is the subject.
* No resumability: the SSE events carry `id:` fields, but `Last-Event-ID` replay is not implemented.
* No TLS, no authentication, no multi-client handling — one parent, one child, one connection.
