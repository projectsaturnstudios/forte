---
type: Architecture
title: Scaling
description: RESP codec, async Redis connect with auth, db and TLS, reconnect, publish queue, the serialized payload.
resource: src/Servers/Forte/Publishing/
tags: [forte, redis, scaling, pubsub, resp]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-12T03:45:26Z }
sources:
  - id: factory
    resource: src/Servers/Forte/Publishing/RedisClientFactory.php
    title: RedisClientFactory.php
  - id: connection
    resource: src/Servers/Forte/Publishing/RedisConnection.php
    title: RedisConnection.php
  - id: client
    resource: src/Servers/Forte/Publishing/RedisClient.php
    title: RedisClient.php
  - id: provider
    resource: src/Servers/Forte/Publishing/RedisPubSubProvider.php
    title: RedisPubSubProvider.php
  - id: handler
    resource: src/Protocols/Pusher/PusherPubSubIncomingMessageHandler.php
    title: PusherPubSubIncomingMessageHandler.php
---

# Scaling

Replaces `clue/redis-react`. `RedisClient`, `RedisPublishClient`, `RedisSubscribeClient`, `RedisPubSubProvider` are Reverb's, forked; `Resp`, `RespError`, `RedisConnection`, `RedisClientFactory` are Forte's.

## Connect (`RedisClientFactory::make`)

URL `redis://` or `rediss://` (TLS), query `username`, `password`, `db`. Async connect; writable watch; no peer name → refused. TLS: non-blocking `enable_crypto` armed both ways until done. Then `AUTH` (with username when given), `SELECT` when db ≠ 0, then resolve. Failure → connection closed, promise rejected.

## Connection (`RedisConnection`)

`Resp` codec; FIFO of `Deferred` replies matched to commands in order. After `SUBSCRIBE`, push `message` frames → `on('message', fn ($channel, $payload))`. Close rejects pending replies, emits `close`. Listener list is its own (`on`, `listeners`, protected `emit`); `end()` = `close()` (clue's name Reverb calls).

## Reconnect

`RedisClient` retries every 1 s up to `scaling.server.timeout` (default 60 s), then throws. Publisher queues events while disconnected, flushes on reconnect. Close listener registered once per client.

## Payload

Channel `scaling.channel` (default `reverb`). Message JSON carries `serialize($app)` (`ProjectSaturnStudios\Forte\Application`) and the event; receiver unserializes with explicit `allowed_classes`. Consequence: Forte and Reverb nodes can't share a channel (class names differ) — switch a fleet in one deploy. Anyone who can publish there reaches every client → Redis is trusted infra (SECURITY.md).
