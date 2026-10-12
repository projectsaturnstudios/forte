# Log

## 2026-10-12

* **Creation**: Bundle created from the repository history (Reverb fork at `74c8c40` through the ReactPHP removal): [overview](/architecture/overview.md), [loop](/architecture/loop.md), [transport](/architecture/transport.md), [promises](/architecture/promises.md), [scaling](/architecture/scaling.md), [reverb compatibility](/guides/reverb-compatibility.md), [testing](/runbooks/testing.md).
* **ReactPHP removed**: `react/promise` and `evenement/evenement` left `require`; `ratchet/pawl`, `react/async`, `react/http`, `react/promise-timer` left `require-dev`. Forte's own `Promise`/`Deferred` replace React's; the suite's WebSocket and HTTP clients run on Forte's loop. See [promises](/architecture/promises.md), [testing](/runbooks/testing.md).
* **Reverb config adopted**: an app's `config/reverb.php` drives Forte until `config/forte.php` is published. See [reverb compatibility](/guides/reverb-compatibility.md).
* **Pulse recorder aliases**: `Laravel\Reverb\Pulse\Recorders\ReverbConnections` and `ReverbMessages` resolve to Forte's recorders when laravel/reverb is gone. See [reverb compatibility](/guides/reverb-compatibility.md).
