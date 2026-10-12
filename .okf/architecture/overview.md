---
type: Architecture
title: Overview
description: Which half of the tree is Reverb's and which is Forte's, the no-ReactPHP rule, the request path from accept to channel.
resource: src/
tags: [forte, reverb, fork, architecture]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-12T03:45:26Z }
sources:
  - id: composer
    resource: composer.json
    title: composer.json
  - id: factory
    resource: src/Servers/Forte/Factory.php
    title: Factory.php
  - id: license
    resource: LICENSE
    title: LICENSE
---

# Overview

## Two halves

Reverb forked at `74c8c40` (MIT, credit in `LICENSE` + README). `Laravel\Reverb` → `ProjectSaturnStudios\Forte`, directory shape kept so upstream diffs port.

| Half | Paths | Rule |
|---|---|---|
| Forte | `src/Servers/Forte/` (loop, HTTP/TLS server, WebSocket connection, Redis publishing), `src/Promise/` | Forte's design. |
| Reverb | everything else: `Protocols/Pusher/` (channels, presence, events, HTTP API controllers, metrics), `Pulse/`, `Jobs/`, `Console/`, config | Change minimally. Upstream fix must still port. |

Reverb's `src/Servers/Reverb/` (ReactPHP transport) is gone; `src/Servers/Forte/` replaces it behind the same contracts (`Contracts\WebSocketConnection`, `PubSubProvider`, `ServerProvider`).

## No ReactPHP

ReactPHP has no epoll/kqueue backend usable from userland, so Forte replaces the whole stack, not one package. `composer.json` carries no `react/*`, `clue/*`, `evenement/*`, `ratchet/pawl`, dev included. Forte supplies:

* loop → [loop](/architecture/loop.md)
* TCP/TLS/HTTP server, WebSocket connection → [transport](/architecture/transport.md)
* promises → [promises](/architecture/promises.md)
* Redis client → [scaling](/architecture/scaling.md)
* test WebSocket + HTTP clients → [testing](/runbooks/testing.md)

Kept: `ratchet/rfc6455` (frame parser `MessageBuffer`, `ServerNegotiator`, `ResponseVerifier`; no loop), `guzzlehttp/psr7`, symfony routing/http-foundation (Reverb's router), `pusher/pusher-php-server`.

## Request path

`Factory::make()` → binds `LoopInterface` in container (metrics handler shares it) → `Http\Server` listens → accept → `Http\Connection` (TLS if cert) → bytes buffered until full HTTP request → `Router` (symfony `UrlMatcher` over Pusher routes: `/app/{key}`, `/apps/{id}/events|batch_events|connections|channels|channels/{c}|channels/{c}/users|users/{u}/terminate_connections`, `/up`) → controller. WebSocket route: `ServerNegotiator` handshake → `Servers\Forte\Connection` wraps HTTP connection → Reverb's `Protocols\Pusher\Server` handles frames. Controllers return `Response` or `Promise<Response>`; router sends then closes.
