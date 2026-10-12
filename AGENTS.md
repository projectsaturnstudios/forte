# Agent guidance — projectsaturnstudios/forte

1. **Read [`.okf/index.md`](.okf/index.md) first** before changing the transport, the forked Reverb code, the config, or the install command. Open only the concepts the change touches.
2. **Drop-in for Reverb.** A Reverb app swaps the package and the start command; nothing else changes. `REVERB_*` env keys, an app's `config/reverb.php`, the `reverb` broadcasting connection, Echo, Pulse recorders and cards keep working. A change that makes a Reverb app edit anything else is a bug. See [`.okf/guides/reverb-compatibility.md`](.okf/guides/reverb-compatibility.md).
3. **No ReactPHP.** No `react/*`, `clue/*`, `evenement/*` or `ratchet/pawl`, in `require` or `require-dev`. Forte supplies the loop, server, TLS, Redis client, promises and test clients. `ratchet/rfc6455` stays: it parses frames and handshakes, it has no loop. See [`.okf/architecture/overview.md`](.okf/architecture/overview.md).
4. **Two halves.** `src/Servers/Forte/` and `src/Promise/` are Forte's. The rest is Reverb's tree at `74c8c40`, renamespaced; change it as little as the fix allows so upstream fixes port.
5. **The loop is level-triggered and keyed by `(int) $stream`**, the resource id, not the kernel fd. Callbacks must not block. epoll, kqueue, select behave the same through `Loop`. See [`.okf/architecture/loop.md`](.okf/architecture/loop.md).
6. **Promises settle synchronously.** No microtask queue; handlers run when the promise settles. See [`.okf/architecture/promises.md`](.okf/architecture/promises.md).
7. **TLS reads drain.** OpenSSL can hold decrypted bytes the kernel no longer reports; a TLS read loops until a short read. Never trust one read per wakeup on TLS. See [`.okf/architecture/transport.md`](.okf/architecture/transport.md).
8. **Tests are Pest v4**, written first. Feature tests run a real server on `0.0.0.0:8080` with clients on the same Forte loop (`await()`, `runLoopFor()`); the `redis` group needs Redis on 6379. Every change passes on Linux (epoll and select) and macOS (kqueue). See [`.okf/runbooks/testing.md`](.okf/runbooks/testing.md).
9. **PHP 8.4+, Laravel 11/12/13.** Match upstream Reverb's supported Laravel lines.
10. **Durable facts go in `.okf`.** Update the matching concept, bump its `generated.at`, append `.okf/log.md`. The bundle documents the package, never a session.
11. **Version**: tags drive Packagist; there is no version key in `composer.json`.
