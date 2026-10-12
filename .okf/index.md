---
okf_version: "0.2"
---

# Forte

* [Overview](architecture/overview.md) - Which half of the tree is Reverb's and which is Forte's, the no-ReactPHP rule, the request path from accept to channel.

# Architecture

* [Loop](architecture/loop.md) - Driver pick (epoll, kqueue, select), resource-id keys, level-triggered dispatch, timers, the select ceiling.
* [Transport](architecture/transport.md) - Listener, TLS handshake and drain, write cap, HTTP request buffering, WebSocket upgrade, frames after the handshake.
* [Promises](architecture/promises.md) - Forte's synchronous Promise and Deferred: what Reverb's code calls, and why no microtask queue.
* [Scaling](architecture/scaling.md) - RESP codec, async Redis connect with auth, db and TLS, reconnect, publish queue, the serialized payload.

# Guides

* [Reverb compatibility](guides/reverb-compatibility.md) - Env fallback, config/reverb.php adoption, REVERB_SERVER=reverb, broadcasting driver, Pulse aliases, forte:install.

# Runbooks

* [Testing](runbooks/testing.md) - Pest suite on Forte's loop, test clients, port 8080, redis group, per-driver runs, CI and Autobahn.
