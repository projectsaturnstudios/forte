---
type: Architecture
title: Loop
description: Driver pick (epoll, kqueue, select), resource-id keys, level-triggered dispatch, timers, the select ceiling.
resource: src/Servers/Forte/Loop/Loop.php
tags: [forte, loop, epoll, kqueue, select, timers]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-12T04:48:47Z }
sources:
  - id: loop
    resource: src/Servers/Forte/Loop/Loop.php
    title: Loop.php
  - id: drivers
    resource: src/Servers/Forte/Loop/Drivers/
    title: Drivers
  - id: start
    resource: src/Servers/Forte/Console/Commands/StartServer.php
    title: StartServer.php
---

# Loop

## Driver pick

`Loop::create(?string $forced)`: forced name, else `epoll_create1` exists → epoll, `kqueue` exists → kqueue, else select. Forced by `FORTE_LOOP` (config `forte.servers.forte.loop`) or `forte:start --loop=`. Unknown name → `InvalidArgumentException`. `forte:start` prints the driver; on select it warns with the PIE command. Extensions are `suggest`, not `require`: install before or after deploy.

## Keys

Watches keyed by `(int) $stream` — PHP resource id, not kernel fd. Drivers resolve the fd themselves. `streams[id]` holds the resource so it outlives the caller's reference.

## Dispatch

Level-triggered on all three drivers: unread bytes report again next wait. One read per wakeup is enough for plain TCP. Not for TLS → [transport](/architecture/transport.md).

`onReadable/onWritable` replace the callback for that stream; mask change synced to driver. Driver refusal (select over ceiling) → map change rolled back, `RuntimeException` → server refuses that socket, keeps serving.

## tick(?float $timeout)

1. run future ticks queued before this tick (`futureTick()`, FIFO; ones they queue wait for the next tick)
2. fire due timers
3. stopped by a timer during `run()` → return before blocking
4. wait = 0 if a future tick is queued, else min(next timer, timeout); null + no watches → stop (no idle spin)
5. driver wait → readers then writers per ready stream

`run()` = tick until `stop()`. Tests drive `tick()` directly.

## Timers

`SplMinHeap` of `[due, seq, Timer]`; seq keeps insertion order for equal dues. Periodic timers reschedule at `max(deadline + interval, now)`: cadence kept, no catch-up burst after a stall. Timers fire at the start of `tick()`, so one added during I/O dispatch (even `addTimer(0, …)`) fires on the next tick.

## select ceiling

`SelectDriver::MAX_DESCRIPTORS = 1024` (glibc `FD_SETSIZE`); `stream_select()` misbehaves above it, so the driver refuses instead. epoll/kqueue: bound is `ulimit -n`.
