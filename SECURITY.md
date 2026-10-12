# Security Policy

## Supported versions

Forte is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the next
release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on the
[projectsaturnstudios/forte](https://github.com/projectsaturnstudios/forte) repository's **Security** tab.
If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, your platform, PHP build (NTS or ZTS) and loop driver
(`epoll`, `kqueue` or `select`, printed by `forte:start`), and steps to reproduce. Reports are read and
weighed for the release line in development; before 1.0 there is no response-time commitment.

A problem that Laravel Reverb has too, in code Forte forks unchanged, should also go to Laravel through
[their security policy](https://github.com/laravel/reverb/security/policy).

## Security model

Forte is a network server that runs inside your Laravel app, with that app's privileges. It accepts
TCP connections on the configured host and port, serves the Pusher WebSocket protocol and the Pusher
HTTP API, and optionally connects to Redis to share events between nodes.

- **Authentication.** Every HTTP API request must carry a valid Pusher signature (HMAC-SHA256 over the
  method, path and sorted query with the app secret), with `auth_timestamp` inside the tolerance;
  anything else is answered `401`. Private and presence channel subscriptions require the signature
  your app's channel authorization endpoint issues. Both checks are Reverb's code.
- **Origins.** `allowed_origins` on each app is checked against the WebSocket handshake's `Origin`
  header. `['*']`, the default, allows any origin.
- **Limits.** `max_request_size` caps an HTTP request, `max_message_size` caps a WebSocket message,
  `max_connections` caps connections per app, and rate limiting caps client messages when enabled.
  Forte adds one limit of its own: a connection whose unsent output passes 1 MiB, a client that stopped
  reading, is closed instead of buffered without bound.
- **TLS.** Forte terminates TLS only when you give it a certificate (`--hostname` with a Herd or Valet
  certificate, or `servers.forte.options.tls`). The handshake runs on the loop without blocking other
  connections. Without a certificate Forte speaks plain TCP; put a TLS proxy in front in production.
- **Descriptors.** On the `stream_select()` fallback the loop refuses a connection beyond 1024
  descriptors and keeps serving the rest. epoll and kqueue have no such ceiling; the process's open-file
  limit is the bound.
- **Redis.** Scaling messages carry serialized PHP objects. Forte unserializes them with an explicit
  `allowed_classes` list, but anyone who can publish to the scaling channel can inject events to every
  connected client. Treat that Redis as trusted infrastructure: authenticate it and keep it off public
  networks.

A report is in scope when Forte accepts what it should refuse: an HTTP API call without a valid
signature, a subscription without valid channel auth, a message past a configured limit, a crash or
unbounded memory growth reachable from a client, a TLS connection that blocks the loop. Effects of
configuration you chose, such as `allowed_origins => ['*']` or an unauthenticated Redis, are documented
behaviour.
