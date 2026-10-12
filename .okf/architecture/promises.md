---
type: Architecture
title: Promises
description: Forte's synchronous Promise and Deferred - what Reverb's code calls, and why no microtask queue.
resource: src/Promise/Promise.php
tags: [forte, promise, deferred]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-12T03:45:26Z }
sources:
  - id: promise
    resource: src/Promise/Promise.php
    title: Promise.php
  - id: deferred
    resource: src/Promise/Deferred.php
    title: Deferred.php
  - id: tests
    resource: tests/Unit/Promise/PromiseTest.php
    title: PromiseTest.php
---

# Promises

Replaces `react/promise`. Same call shapes Reverb's forked code uses, nothing more.

## API

* `new Promise(fn ($resolve, $reject) => …)` — resolver throws → rejected.
* `Promise::resolve($v)` (returns `$v` if already a Promise), `Promise::reject(Throwable)`, `Promise::all(iterable)` (keys + input order kept, generators ok, first rejection wins, empty → `[]`).
* `then(?onFulfilled, ?onRejected)`, `catch(onRejected)`, `finally(onSettled)` (passes value/reason through; waits for a promise it returns; its throw replaces the outcome).
* `Deferred`: `promise()`, `resolve()`, `reject()`.

## Semantics

* Synchronous: handlers run the moment it settles, or at once on an already-settled promise. Same as react/promise v3 — Reverb's code was written against that ordering.
* Settles once; later resolve/reject ignored.
* Resolving with a promise adopts it; with itself → `LogicException` rejection.
* `catch()` takes any callable, no type-hint filtering (React's `catch` filters by the first parameter's type; Reverb's call sites don't use it).
* No unhandled-rejection reporting.
* `Promise` not `final`: `jasonmccreary/double` builds automatic return values for methods typed `Promise`.

The loop drives I/O; promises only carry results. Nothing schedules.
