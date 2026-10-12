---
type: Architecture
title: Transport
description: Listener, TLS handshake and drain, write cap, HTTP request buffering, WebSocket upgrade, frames after the handshake.
resource: src/Servers/Forte/Http/
tags: [forte, tcp, tls, http, websocket]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-12T04:48:47Z }
sources:
  - id: server
    resource: src/Servers/Forte/Http/Server.php
    title: Server.php
  - id: connection
    resource: src/Servers/Forte/Http/Connection.php
    title: Http/Connection.php
  - id: router
    resource: src/Servers/Forte/Http/Router.php
    title: Router.php
  - id: handshake-factory
    resource: src/Servers/Forte/Http/HandshakeResponseFactory.php
    title: HandshakeResponseFactory.php
  - id: ws
    resource: src/Servers/Forte/Connection.php
    title: Connection.php
---

# Transport

## Listener (`Http\Server`)

`stream_socket_server` with backlog 511 — React's `TcpServer` value; PHP default 32 refuses reconnect storms. URI always `tcp://`; TLS decided per connection from the TLS context, never the scheme. Accept loop drains `stream_socket_accept(…, 0)` until false. `stop()` idempotent.

## Connection (`Http\Connection`)

* **TLS handshake**: `stream_socket_enable_crypto` non-blocking, armed on readable only (armed on writable it spins: socket always writable). `0` → wait for client's next flight. Done → client's first app data may already sit decrypted in OpenSSL → read immediately.
* **TLS drain**: OpenSSL decrypts whole records; bytes past one `fread` can sit in its buffer with the kernel socket empty → no wakeup. TLS reads loop until a short read. Plain TCP: one read per wakeup (level-triggered).
* **Writes**: `send()` only appends to the write buffer and queues one flush per connection per tick (`futureTick`); the flush writes everything queued in one `fwrite`. Remainder → writable watch armed once until drained. Coalescing is what React's buffered streams did for Reverb: an event fanned out while others are in flight costs one syscall per connection, not per frame. Writing each frame immediately (0.10.0) cost ~2× syscalls per delivery and lost ~25% throughput to Reverb at 950 connections on 1 vCPU. Buffer past `MAX_WRITE_BUFFER` (1 MiB; `Server`/`Factory` arg raises it, Autobahn server does) → terminate, checked on every `send()`. Bounds memory for clients that stop reading. Reverb on React buffered unbounded.
* **close()** flushes then closes; **terminate()** now, `onClose` once.
* **Request buffer**: Reverb's `Request::from()` fills `buffer()` until `\r\n\r\n` + body; `max_request_size` enforced there.

## Upgrade

`Router` → `ServerNegotiator` + `RequestVerifier` (rfc6455). `HandshakeResponseFactory` coerces int header values to strings: rfc6455 passes ints (`Sec-WebSocket-Version`), guzzle/psr7 3.x rejects them. Adds `X-Powered-By: Forte`. Bad key → 400, bad version → 426.

## WebSocket (`Servers\Forte\Connection`)

`openBuffer()` installs rfc6455 `MessageBuffer` (`maxMessagePayloadSize` from app's `max_message_size`), routes data + close. Bytes that arrived in the same read as the handshake, past `\r\n\r\n`, are fed to the parser first — a client may send its first frame without waiting for 101. Not an event emitter; callbacks via `onMessage/onControl/onClose`.
