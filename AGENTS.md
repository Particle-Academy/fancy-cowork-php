# Using particle-academy/fancy-cowork — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-cowork
php artisan migrate
php artisan cowork:doctor     # lists every unbound contract; exits 1 until all are bound
```

- **PHP** `^8.4`, **Laravel** 13. Only `illuminate/*` at runtime.
- The Composer name is `particle-academy/fancy-cowork`; the repo is
  `fancy-cowork-php`. Namespace `FancyCowork\`, facade `FancyCowork\Facades\Cowork`.
- The Node twin is `@particle-academy/fancy-cowork` (repo `fancy-cowork-js`). The
  browser side (`useCoworkSession`, presence, cursors, the session indicator) is
  in `@particle-academy/agent-integrations`.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). This package is not yet listed there; it appears once
the registry carries it. Server-side packages are found with
`search-backend-packages` (stack `php`).

## API essentials

- **Bind the host contracts** in a service provider's `boot()`:
  `Cowork::identify`, `Cowork::members`, `Cowork::authorize`, `Cowork::config`,
  and optionally `Cowork::middleware` and `Cowork::predicates`. The README's
  "Bind the host contracts" shows each.
- **Abilities** asked of `authorize`: `start`, `request`, `activate`, `join`,
  `end`, `follow`, `drive`, `intent:<kind>`, `view:<ref>`.
- **Routes** live under `fancy-cowork.prefix` (`cowork`) behind
  `fancy-cowork.middleware` (`['web']`): `POST sessions`, `GET sessions/{id}`,
  `activate`, `join`, `leave`, `consent`, `end`, `follow`, `intents`,
  `heartbeat`, and `POST auth` for channel auth.
- **Middleware** defaults, in order, each removable by leaving it out:
  `membership`, `authorize`, `payloadSize`, `sameOrigin`, `masking`, `limits`,
  `revision`, `control`, `rateLimit`, `audit`.
- **Broadcasting** uses the package's own `fancy-pusher` driver: set
  `'driver' => 'fancy-pusher'` on a Pusher- or Reverb-shaped connection in
  `config/broadcasting.php`. Works with any Pusher-protocol server.
- **Audit:** listen on `FancyCowork\Contracts\CoworkEvent`; set
  `fancy-cowork.audit.table` to `true` (and run `database/migrations/audit`) to
  write `cowork_audit`.
- **Anonymous visitors:** `FancyCowork\GuestTokens` issues signed guest tokens and
  provides an `identify` resolver (`X-Cowork-Guest` header, ref `guest:<id>`).
- `php artisan cowork:prune` sweeps presence over every open session.

## Traps

- **Nothing works until you bind it.** Every unbound contract refuses with a
  named reason (`cowork.unconfigured.identity`, ...). Run `cowork:doctor`.
- **Only a literal `true` (or `['allow' => true]`) allows.** A truthy `1` or
  `'yes'` refuses. A resolver that throws answers `500 cowork.host.error`.
- **`ref` is the person at the keyboard; `actingFor` is the account.** When your
  auth runs one person inside another's account, return the PERSON as `ref`.
  Presenting a live participant's `ref` with a different `actingFor` answers
  `409 cowork.denied.ref-in-use`.
- **No Pusher SDK is needed.** The `fancy-pusher` driver needs neither
  `pusher/pusher-php-server` nor `laravel/reverb`; do not add them for this. A
  host already running a Reverb server keeps working, since it is the same
  protocol.
- **A `when` naming a predicate you never defined fails closed**, and an unknown
  default middleware name throws when you set the stack.
- **`app` payloads are untrusted data** for you to validate; masking hides values
  from peers, not from someone who can load the page under the same account.
- **Not chat, and no user model.** There are no messages or conversations, and a
  participant is an opaque string ref your resolvers hand it.
