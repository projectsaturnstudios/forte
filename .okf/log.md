# Log

## 2026-10-12

* **0.10.2 — select ceiling by descriptor number**: `SelectDriver::watch()` probes each stream and refuses a descriptor numbered past FD_SETSIZE; `wait()` throws on a `stream_select` failure other than `EINTR`. 0.10.1 counted watched streams only, admitted descriptor 1024+ under load past 1024 connections, and spun at 100% CPU serving no one. See [loop](/architecture/loop.md).
* **0.10.1 — coalesced writes**: `Http\Connection::send()` queues; one flush per connection per loop tick via the new `LoopInterface::futureTick()`. 0.10.0 wrote every frame immediately and fell ~25% behind Reverb at 950 connections on a 1 vCPU box; coalesced, it handles ~49% more events than Reverb on the same box. See [transport](/architecture/transport.md), [loop](/architecture/loop.md).
* **Creation**: Bundle created from the repository history (Reverb fork at `74c8c40` through the ReactPHP removal): [overview](/architecture/overview.md), [loop](/architecture/loop.md), [transport](/architecture/transport.md), [promises](/architecture/promises.md), [scaling](/architecture/scaling.md), [reverb compatibility](/guides/reverb-compatibility.md), [testing](/runbooks/testing.md).
* **ReactPHP removed**: `react/promise` and `evenement/evenement` left `require`; `ratchet/pawl`, `react/async`, `react/http`, `react/promise-timer` left `require-dev`. Forte's own `Promise`/`Deferred` replace React's; the suite's WebSocket and HTTP clients run on Forte's loop. See [promises](/architecture/promises.md), [testing](/runbooks/testing.md).
* **Reverb config adopted**: an app's `config/reverb.php` drives Forte until `config/forte.php` is published. See [reverb compatibility](/guides/reverb-compatibility.md).
* **Pulse recorder aliases**: `Laravel\Reverb\Pulse\Recorders\ReverbConnections` and `ReverbMessages` resolve to Forte's recorders when laravel/reverb is gone. See [reverb compatibility](/guides/reverb-compatibility.md).
