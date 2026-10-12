<div align="center" id="header">

<h1>ProjectSaturn Forte</h1>
<h3>A literal drop-in replacement for Laravel Reverb</h3>

[![CI](https://img.shields.io/github/actions/workflow/status/projectsaturnstudios/forte/tests.yml?style=flat-square&label=CI)](https://github.com/projectsaturnstudios/forte/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/projectsaturnstudios/forte?style=flat-square)](https://packagist.org/packages/projectsaturnstudios/forte)
[![PHP](https://img.shields.io/packagist/dependency-v/projectsaturnstudios/forte/php?style=flat-square&label=PHP)](https://packagist.org/packages/projectsaturnstudios/forte)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com/docs/reverb)
[![License](https://img.shields.io/github/license/projectsaturnstudios/forte?style=flat-square)](LICENSE)
[![Loop](https://img.shields.io/badge/loop-epoll%20%7C%20kqueue%20%7C%20select-blue?style=flat-square)](#installation)
[![OKF](https://img.shields.io/badge/OKF-0.2-6f42c1?style=flat-square&label=knowledge%20bundle)](.okf/index.md)

</div>

Laravel Reverb is a great WebSocket server. It speaks the Pusher protocol, so Echo and every Pusher client already know how to talk to it, and it lives inside your Laravel app. Under the hood it runs on ReactPHP, and ReactPHP's event loop asks the operating system "<b>which of my sockets can I read or write right now?</b>" through `stream_select()` unless you install `ext-uv`, `ev` or `event`. `stream_select()` scans every socket on every wakeup and stops at `FD_SETSIZE`, usually 1024. That's why Reverb's docs tell you to install `ext-uv` before you go past a thousand connections.

Linux has had the better answer for years: `epoll`. macOS has `kqueue`. You register each socket with the kernel once and it hands back only the ones with activity. PHP just never exposed either one to userland, so I wrote [ext-epoll](https://github.com/php-io-extensions/epoll) and [ext-kqueue](https://github.com/php-io-extensions/kqueue): the syscalls, one to one, no opinions.

<b>Forte is Reverb with ReactPHP taken out and those extensions put in.</b> Just replace reverb by installing this package, and making a tiny set of changes to migrate and thats it. 

Nothing in ReactPHP sits on epoll or kqueue directly, so Forte reimplements the part ReactPHP was doing: the event loop, the TCP and TLS server, the HTTP layer the Pusher API rides on, the Redis client for scaling, and the promises that tie them together. Everything above that is Reverb's own code, forked at [`74c8c40`](https://github.com/laravel/reverb/tree/74c8c40) under its MIT license: channels, presence, the Pusher protocol, the HTTP API, Pulse cards, rate limiting. Your `.env` keeps its `REVERB_*` keys, Laravel keeps its `reverb` broadcasting driver, Echo keeps its config. You swap the package and the start command.

No extension yet? Forte falls back to `stream_select()`, so you can deploy first and install the extension whenever you get to it.

## Installation

| | |
|---|---|
| PHP | 8.4 or newer. |
| Laravel | 11, 12 or 13. |
| Loop extension | Optional, recommended. [ext-epoll](https://github.com/php-io-extensions/epoll) on Linux, [ext-kqueue](https://github.com/php-io-extensions/kqueue) on macOS. Without one, Forte runs on `stream_select()` and its 1024-descriptor ceiling. |
| Redis | Only for horizontal scaling, same as Reverb. |

```bash
composer require projectsaturnstudios/forte
```

Then install the loop extension on the machine that runs `forte:start`, with [PIE](https://github.com/php/pie):

```bash
sudo pie install php-io-extensions/epoll   # Linux
pie install php-io-extensions/kqueue       # macOS
```

Forte picks the loop when the server starts: epoll if it's loaded, then kqueue, then `stream_select()`. The start line tells you which one you got:

```console
$ php artisan forte:start

   INFO  Starting server on 0.0.0.0:8080 (epoll).
```

On `stream_select()` it also prints a warning naming the extension to install.

## How to use

### Migrating from Reverb

```bash
composer remove laravel/reverb
composer require projectsaturnstudios/forte
```

Then, wherever your process manager runs Reverb (Supervisor, Forge daemon, systemd), change the command:

| Reverb | Forte |
|---|---|
| `php artisan reverb:start` | `php artisan forte:start` |
| `php artisan reverb:restart` | `php artisan forte:restart` |

`forte:start` takes Reverb's flags, `--host`, `--port`, `--path`, `--hostname` and `--debug`, plus `--loop=epoll|kqueue|select` to force a driver.

That's the migration. What stays as it is:

* **`.env`**: Forte reads every `REVERB_*` key Reverb reads. A `FORTE_*` key of the same name wins when both are set, and `FORTE_LOOP` forces the loop driver.
* **`config/reverb.php`**: if your app published it, Forte runs on it, custom apps and allowed origins included. Publish Forte's own file with `php artisan vendor:publish --tag=forte-config` when you're ready, and from then on `config/forte.php` is the one that counts.
* **`config/broadcasting.php`**: the `reverb` connection is Laravel's, so it doesn't change. Your app still broadcasts with `BROADCAST_CONNECTION=reverb`.
* **Echo and `VITE_REVERB_*`**: same protocol, same host, same port, same keys. No front-end rebuild.
* **Scaling**: `REVERB_SCALING_ENABLED`, `REVERB_SCALING_CHANNEL` and your `REDIS_*` settings work as they did. Switch every node in one deploy: each node serializes its own `Application` class into the Redis messages, so a Reverb node and a Forte node can't read each other's.
* **Pulse**: the recorder classes Reverb's docs put in `config/pulse.php` (`Laravel\Reverb\Pulse\Recorders\ReverbConnections`, `ReverbMessages`) resolve to Forte's recorders, and the `reverb.connections` and `reverb.messages` cards still render.

### Fresh Install

```bash
composer require projectsaturnstudios/forte
php artisan forte:install
```

`forte:install` does what `reverb:install` does. It writes the `REVERB_*` and `VITE_REVERB_*` keys into `.env` with a generated app id, key and secret, publishes `config/forte.php`, adds the `reverb` connection to `config/broadcasting.php`, offers to enable broadcasting (`install:broadcasting` without pulling in `laravel/reverb`), and offers to switch `BROADCAST_CONNECTION` to `reverb`. Last, it tells you which loop extension to install if this machine doesn't have it.

Then start the server:

```bash
php artisan forte:start
```

From here everything in [Laravel's broadcasting docs](https://laravel.com/docs/broadcasting) and [Reverb's docs](https://laravel.com/docs/reverb) applies: events that implement `ShouldBroadcast`, channel authorization in `routes/channels.php`, Echo on the client. Running it in production is the same too, with `forte:start` in place of `reverb:start`: one process under a supervisor, raise the open-file limit (`ulimit -n`) for the connections you expect, and put Nginx in front for TLS, or let `--hostname` pick up a Herd or Valet certificate locally.

## AI-assisted development

This codebase was built with LLM assistance under human direction. Prompts, reviews, and architectural decisions were owned by a professional software engineer — the model accelerates drafting and exploration; it does not replace judgment on APIs, dependency direction, or what ships.

## Agentic Documentation

This repository carries an [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md) bundle at [`.okf/`](.okf/): Markdown with YAML frontmatter, readable by you and loadable by a coding agent. It records what the README doesn't: which parts of the tree are Reverb's and which are Forte's, how the loop picks and drives epoll, kqueue or `stream_select()`, how a connection moves from TCP accept through TLS and the WebSocket handshake, what scaling sends over Redis, how a Reverb app's `.env` and config keep working, and a log of every change that altered behaviour.

Start at [`.okf/index.md`](.okf/index.md). [`AGENTS.md`](AGENTS.md) is the short rule sheet an agent reads before touching the transport, the forked Reverb code, or the config; each rule points into the bundle for the reason behind it.

Point your agent at the repo and it has the same context the maintainer has. Point yourself at it when the README's answer is "it does X" and you want "because".

## Resources

* [Laravel Reverb](https://laravel.com/docs/reverb) and [Broadcasting](https://laravel.com/docs/broadcasting): the docs for everything Forte keeps. Where they say `reverb:start`, read `forte:start`.
* [laravel/reverb](https://github.com/laravel/reverb): the upstream Forte forks from.
* [ext-epoll](https://github.com/php-io-extensions/epoll) and [ext-kqueue](https://github.com/php-io-extensions/kqueue): the extensions Forte's loop runs on.
* [Pusher Channels protocol](https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/) and [HTTP API](https://pusher.com/docs/channels/library_auth_reference/rest-api/): the wire format Forte serves.
* [Open Knowledge Format](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md): the spec the [`.okf/`](.okf/) bundle follows.

## Running the test suite

The suite is Pest v4: Reverb's own tests, moved onto Forte's loop, plus Forte's tests for the loop drivers, the TLS server and the Redis client. The feature tests start a real server on `0.0.0.0:8080` and talk to it with a WebSocket client and an HTTP client that also run on Forte's loop, so nothing else may be listening on 8080. The `redis` group needs Redis on `127.0.0.1:6379`.

```bash
git clone https://github.com/projectsaturnstudios/forte.git
cd forte
composer install
vendor/bin/pest
```

```console
Tests:    8 skipped, 383 passed (1065 assertions)
```

That run is Linux with ext-epoll. Skips are the other platform's driver and a test that needs `ulimit -n` above 1024. Run it once per driver you have:

```bash
FORTE_LOOP=select vendor/bin/pest
FORTE_LOOP=epoll  vendor/bin/pest   # Linux, ext-epoll loaded
FORTE_LOOP=kqueue vendor/bin/pest   # macOS, ext-kqueue loaded
vendor/bin/pest --exclude-group=redis   # no Redis on this machine
```

CI runs the suite on all three drivers with PHP 8.4 and 8.5, NTS and ZTS, and the [Autobahn](https://github.com/crossbario/autobahn-testsuite) WebSocket compliance suite against the epoll and select loops.

## Contributing

Open an issue first for anything beyond a typo. Pull requests come with a Pest test for the behaviour and keep CI green on every driver. Code under `src/Servers/Forte/` and `src/Promise/` is Forte's; everything else is Reverb's, and changes there stay as close to upstream as the fix allows so upstream fixes keep porting cleanly. Forte depends on no ReactPHP package, dev dependencies included. Read [`AGENTS.md`](AGENTS.md) for the rules the code follows.

## Security

Forte is a network server: it accepts connections, terminates TLS when you give it a certificate, and checks Pusher signatures on every HTTP API call. See [SECURITY.md](SECURITY.md) for the model and how to report a vulnerability.

## License

MIT. See [LICENSE](LICENSE). Forte is a fork of [Laravel Reverb](https://github.com/laravel/reverb) by Taylor Otwell, Joe Dixon and the Laravel contributors, under the same license.
