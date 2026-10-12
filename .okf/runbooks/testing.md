---
type: Runbook
title: Testing
description: Pest suite on Forte's loop, test clients, port 8080, redis group, per-driver runs, CI and Autobahn.
resource: tests/
tags: [forte, pest, testing, ci, autobahn]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-12T03:45:26Z }
sources:
  - id: case
    resource: tests/ForteTestCase.php
    title: ForteTestCase.php
  - id: helpers
    resource: tests/Helpers/Forte.php
    title: Helpers/Forte.php
  - id: ws-client
    resource: tests/WebSocketClient.php
    title: WebSocketClient.php
  - id: http-client
    resource: tests/HttpClient.php
    title: HttpClient.php
  - id: ci
    resource: .github/workflows/tests.yml
    title: tests.yml
  - id: autobahn
    resource: .github/workflows/spec-tests.yml
    title: spec-tests.yml
---

# Testing

## Run

```bash
composer install
vendor/bin/pest                          # driver auto-picked
FORTE_LOOP=select vendor/bin/pest        # each driver the machine has
vendor/bin/pest --exclude-group=redis    # no Redis on 127.0.0.1:6379
```

Feature tests bind `0.0.0.0:8080`: a running `forte:start` or anything else there fails every feature test with "Address already in use". Skips: other platform's driver, select's 1024 test without `ulimit -n` > 1024.

## Shape

Pest v4. Reverb's tests, moved onto Forte's loop, + Forte's (loop drivers, TLS, Redis, promises, compatibility).

* `ForteTestCase` starts a server per test on a fresh `Loop::create(FORTE_LOOP)`; `Factory::make` binds it as `LoopInterface`.
* `await(Promise, timeout = 10)` ticks `app(LoopInterface)` until settled, returns value or throws reason; `TimeoutException` past deadline. `runLoopFor(seconds)` ticks for a duration.
* `WebSocketClient` — Pawl's replacement: same handshake headers (Origin defaults to host), masked frames, auto-pong, one `close` event (first code wins), `response` = 101 PSR-7 response. Frames that arrive with the 101 are fed a tick later so the awaiting test attaches listeners first.
* `HttpClient` — React Browser's replacement: one request, `Connection: close`, caller headers win, 4xx/5xx → `ResponseException` (code = status), refused → `RuntimeException`.
* `TestConnection`, `connect()`, `subscribe()`, `send()`, `disconnect()` keep Reverb's helper API.
* Server and clients share one process + loop: never block (`sleep()` only where a test needs wall time to pass).

## Platforms

Linux: epoll + select. macOS: kqueue + select. Both before merge.

## CI

`tests.yml`: PHP 8.4/8.5 × NTS/ZTS × epoll (ubuntu, PIE ext-epoll), kqueue (macos-15, PIE ext-kqueue), select (ubuntu, no ext); Redis started per OS. `spec-tests.yml`: Autobahn fuzzing client vs `tests/Specification/spec-server.php` on epoll and select; `spec-analyze.php` fails the job on any case other than `OK` or `NON-STRICT` (`INFORMATIONAL` skipped).
