# Changelog

Notable changes to `particle-academy/fancy-cowork`.

**Pre-1.0: breaking changes land in MINOR releases.** Read those entries before upgrading.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

## [0.5.0] - 2026-11-24

### Added

- **`fancy-cowork.publish`: how hard the `fancy-pusher` driver tries.** `retries`
  (default `2`), `retry_budget_ms` (default `3000`, the most it waits in total) and
  `backoff_ms` (default `250`, the first wait when the service names none, doubled per
  retry). The defaults keep the old bounds. Nothing to do; raise them only if your
  socket service rate-limits for longer, and remember the wait holds a PHP worker after
  the response.
- **`FancyCowork\Broadcasting\BroadcastFailed`**, dispatched for a broadcast that could
  not be delivered: `channel`, `event`, `session`, `reason`, never the payload. Listen for
  it to count, alert or re-queue. It is not one of the eight audit events.

### Fixed

- **An undelivered broadcast was dropped with no trace.** Broadcasts go out after the
  response (0.4.2), and the manager caught every failure there and said nothing, so a
  cursor or session change the socket service refused simply vanished while the caller
  had its `202`. When lab test 87 lost one cursor move in 20, nothing on the server
  could say whether the publish had failed. Now a failure is logged as a warning
  and dispatched as `BroadcastFailed`. Nothing to do; add a listener if you want more
  than the log line.
- **Only a `429` was retried.** A `500`, `502`, `503`, `504` or a failed connection
  (the service restarting, a dropped keep-alive) lost the event on the first attempt.
  They are now retried within the same budget. When the service names no
  `Retry-After`, the first wait is 250 ms (was 1 s), doubled per retry. Nothing to do.

### Changed

- **Docs:** `AGENTS.md` is now the guide to using this package (install, how to find it on the Fancy MCP catalog server once it is listed, API essentials, traps). The contributor notes it held moved to `CONTRIBUTING.md`, and `CLAUDE.md` is now a symlink to it. The README points to the MCP server and to `AGENTS.md`. No code changed; nothing to do.
- CI: re-pin org third-party allowlist to 2614224

## [0.4.2] - 2026-11-16

### Changed

- **Broadcasts go out after the response.** A consumer's `/follow` and `/join` POSTs hung
  for over 10 and 20 seconds: their broadcaster is synchronous and their socket server
  was answering 429, so each request waited on its broadcast while holding one of four
  PHP workers, and the browser's co-work queue waited on the follow. The row lock was
  never held across a broadcast (it runs after the transaction commits), but the
  broadcast still ran inside the request. Now `fancy-cowork.broadcast_after_response`
  (default true) queues them and sends them on the kernel's terminate.
  **Nothing to do for HTTP traffic.** A long-running process that is not a request (a
  queue worker calling the manager) calls `Cowork::flushBroadcasts()`, or sets the
  option to false.
- **A change that changes nothing broadcasts nothing.** A join by someone already joined
  (the same person in a second tab) was already allowed without a state change; it no
  longer broadcasts `cowork.session` either.

### Fixed

- **README: Laravel's `/broadcasting/auth` is not equivalent to `POST {prefix}/auth`** for
  the presence channel. Laravel keys a presence member by the authenticated account, so
  two people on one account ("View As") merged into one member and presence described
  the wrong person. The README now says to authorize co-work channels with
  `POST {prefix}/auth`, which keys each member by its co-work ref. Documentation only;
  no code changed. **If your Echo authorizes `cowork.*` channels through
  `/broadcasting/auth` and people can share an account, point it at `/cowork/auth`.**

## [0.4.1] - 2026-11-15

### Added

- **The re-sync publishes ranked control.** Under `control.mode: 'ranked'`,
  `GET /cowork/sessions/{id}` now carries each participant's effective `rank` (0 for a ref
  the config does not rank) and `control.scope` (`view` or `session`) and
  `control.idleMs`. The server is the authority, so a browser no longer needs to know
  other people's roles to hold its person; agent-integrations 0.65.0 uses them, falling
  back to the page's own rank function only where the server sent nothing.
  `Pipeline::rankedSummary($state, $config)` returns the same thing. Nothing changes for
  any other mode. Shared fixtures re-pinned (`d036861f`, two `expectRanked` cases).
  **Nothing to do**: the fields are additive and an older client ignores them.

## [0.4.0] - 2026-11-15

### Added

- **Live presence and abandoned sessions.** `POST {prefix}/sessions/{id}/heartbeat`
  (a live participant; 204), and every request a participant makes, refresh its
  `lastSeen`. A participant not seen for `presence.idle_ms` (default 120000) is removed
  (`PeerLeft`, `reason: expired`); when the starter is gone the session ends
  (`SessionEnded`, `reason: starter-left`) unless `presence.end_when_starter_leaves` is
  false; a session empty for `presence.empty_ms` (default 600000) ends (`reason: empty`).
  The sweep runs on every read and write of a session, so nothing needs scheduling, and
  `cowork:prune` is the backstop for sessions nobody touches. `GET sessions/{id}` gives
  each participant `lastSeen` and `online`. **Run the new migration**
  (`2026_11_15_000000_add_last_seen_to_cowork_participants`, one nullable column).
  Before this, a closed browser left its participant, and the session, live forever.

- **`session.endReason` on the re-sync** (`GET sessions/{id}`): `ended` (an explicit end),
  `starter-left` or `empty`, and `null` while live, so a client can tell why a session
  ended without reading the audit events. Stored in a new nullable `end_reason` column
  (migration `2026_11_15_000100_add_end_reason_to_cowork_sessions`; run it). A session
  that ended before the column existed reads as `ended`.
- **The publisher retries a 429** from the socket server's events API after its
  `Retry-After` (seconds or an HTTP date), at most 2 retries and never more than 3 s of
  waiting in total, then `BroadcastException` as before. Genie's WebSockets service
  rate-limits event publishing. Other refusals are not retried.

### Changed

- **One ref, one identity.** A `join`, intent or heartbeat that presents a live
  participant's `ref` with a DIFFERENT `actingFor` is refused `409
  cowork.denied.ref-in-use`. Before this the two were silently merged into one
  participant: one person shown, the other browser's edits skipped as the receiver's own,
  and nobody ranked above anybody. **Nothing to do unless your `identify` returned the same
  `ref` for two different people**, typically an admin viewing as a user returning the
  user's ref: return the ADMIN's ref there, with the user's as `actingFor` (README, "ref is
  the person at the keyboard"). The same person in a second tab (the same `actingFor`) is
  still allowed.
- **The publish body is ASCII-only JSON** (non-ASCII escaped as `\uXXXX`), with
  `body_md5` over exactly those bytes. Some Pusher-protocol servers, Genie's WebSockets
  service among them, read the body as Latin-1, so raw UTF-8 arrived garbled. Nothing to
  do: receivers decode the same text.

## [0.3.0] - 2026-11-15

### Added

- **Ranked control can span the session**: `control: { mode: 'ranked', scope: 'session' }`.
  A higher rank's committed intent on ANY view then blocks every lower rank's view
  intents on every view, until `idleMs` after the latest such intent (`blockedUntil`).
  It is the mode for mirrored co-work (mutual follow plus shared fields), where each
  person writes only their own view, so per-view ranking would never block anyone.
  Presence intents stay exempt. **Nothing to do:** the default scope is `view`, exactly
  the previous behaviour. Shared fixtures re-pinned at `bcf40fb6...7106`.

## [0.2.0] - 2026-11-14

**The first version on Packagist.** `v0.1.0` was tagged but never submitted to Packagist,
and it never will be: it reached browsers only through Laravel's own pusher/reverb
broadcaster, which needs `pusher/pusher-php-server`, and the owner refused that and
`laravel/reverb` (q_63a8ab0c). Its lab certificate was signed against a Reverb host. 0.2.0
publishes with its own client instead, and its lab benchmark runs on Genie's WebSockets service.
**Nothing to do** unless you installed `v0.1.0` from git: then switch your broadcasting
connection to the driver below.

### Added

- **`fancy-pusher` broadcast driver**: publishes to any Pusher-protocol server (Genie's
  WebSockets service, Sockudo, Reverb, Pusher) with an HMAC-signed `POST
  /apps/{app_id}/events` over Laravel's HTTP client, and signs `/broadcasting/auth`.
  Our own code, pinned to the Pusher protocol's documented HTTP API and private-channel
  signature examples. It refuses a payload over the protocol's 10 KB limit before
  sending, sends at most 100 channels per request, excludes the sender's socket, and
  raises `BroadcastException` (never carrying the secret) on a non-2xx answer. Point a
  Pusher- or Reverb-shaped connection at it (`'driver' => 'fancy-pusher'`, with `key`,
  `secret`, `app_id` and `options.host` / `port` / `scheme`). A host already running
  Reverb keeps working: it is the same protocol.
- **The driver reuses its connection.** One Guzzle handler for the client's lifetime
  (set through Laravel's `PendingRequest::setHandler`, so `Http::fake` still
  intercepts), so a publish after the first in a process skips the TLS handshake.
  Against Genie's WebSockets service: p50 1402 to 1463 ms per publish with a fresh
  handler, 2.0 to 2.5 ms reused (p95 2.8 to 3.4 ms). The first publish in a process
  still pays the handshake; plain PHP-FPM starts every request fresh.
- **Presence intents for server-routed presence.** `cursor` (`route` plus `anchor`,
  `fx`, `fy` in [0, 1], or `x`, `y` >= 0) and `activity` (`handle`, `active`), posted
  to the intent endpoint and broadcast with the sender stamped from `identify`. For
  socket servers that do not stamp a client event's sender: **Sockudo, and so Genie's
  WebSockets service, needs them**. `authorize` asks `intent:cursor` /
  `intent:activity`; no view, revision, limits, masking or control; no state. A cursor
  spends only `rate.cursorsPerSecond` (new, default 10); an activity spends the
  ordinary intent budget. The shared fixtures gain three cases, re-pinned at
  `e2bcca74...d6b5`.

### Changed

- `cowork:doctor` names `fancy-pusher` when the broadcast driver cannot reach a browser,
  and no longer suggests Reverb. `composer.json` no longer suggests `laravel/reverb`.

### Dependencies

- **Still no third-party code**, and now explicitly **no `laravel/reverb` and no
  `pusher/pusher-php-server`**. `guzzlehttp/guzzle` (`^7.8.2 || ^8.0`) is now declared
  directly, because the driver names its handler: it is the same constraint
  `illuminate/http` already requires, so nothing new is installed.

## [0.1.0] - 2026-11-14

Council change **CC-fancy-0037** (owner approved 2026-11-13): generic co-working
sessions. The first release. Generic co-working, not any one consumer's spec; no chat; no
assumptions about the host's models.

### Added

- **Host contracts, default deny.** `Cowork::identify`, `members`, `authorize`, `config`,
  `middleware`, `predicates`. Every one starts unbound, and an unbound contract refuses
  with a named reason (`cowork.unconfigured.identity`, `.config`, `.members`,
  `.authorize`). **Installing this changes nothing until you bind them**; `php artisan
  cowork:doctor` lists what is unbound and exits non-zero until everything is.
  Only a literal `true` or `['allow' => true]` from a resolver allows.
- **The intent pipeline**, contract v1: validation, the session gate, and ten default
  middleware in a pinned order (`membership`, `authorize`, `payloadSize`, `sameOrigin`,
  `masking`, `limits`, `revision`, `control`, `rateLimit`, `audit`), each removable; host
  middleware anywhere, as a closure or an `IntentMiddleware` class. Control modes `free`,
  `ranked` and `baton`; consent `none`, `notify` and `accept`; routing `server` or
  `direct`, all chosen by the host per session.
- **Sessions and views.** `cowork_sessions` and `cowork_participants`, opaque refs only and
  no foreign key to any host table. Per-participant views with follow and drive; a
  requested session goes live on an authorised activate.
- **Routes** under `cowork/`: start or request, re-sync, activate, join, consent, leave,
  end, follow, intents, and channel auth. Relayed intents are broadcast on
  `presence-cowork.session.{id}` to everyone but the sender's socket.
- **Channel auth** for `presence-cowork.session.{id}` and
  `private-cowork.participant.{ref}`, through `POST cowork/auth` (signed, works for
  anonymous participants) and registered with Laravel's `/broadcasting/auth`.
- **Audit events**: `SessionRequested`, `SessionActivated`, `PeerJoined`, `PeerLeft`,
  `ConsentGiven`, `ControlChanged`, `IntentRelayed`, `SessionEnded`, each carrying `ref` and
  `actingFor`, never an intent's value. An **optional** `cowork_audit` table behind
  `fancy-cowork.audit.table` (off by default).
- **Optional signed guest tokens** (`GuestTokens`): an `identify` resolver for anonymous
  visitors, which a host may bind or ignore.
- **Parity fixtures**: `conformance/cowork-v1.json`, byte-identical with
  `@particle-academy/fancy-cowork` and agent-integrations, pinned by SHA-256 and run in
  full by the suite. `tests/sabotage.php` breaks 42 clauses one at a time and the suite
  catches every one.

### Dependencies

- **No third-party code.** Runtime requires only `illuminate/*` 13 components.
  `laravel/framework` is a **test** dependency under the framework carve-out (it is the
  framework this package integrates with); `pestphp/pest` ^5 runs the suite. Reverb is
  suggested, not required: any Pusher-protocol server works.

### Changed

- **`__proto__`, `constructor` and `prototype` are never a ref**, matching the JS legs,
  which fixed a prototype-polluting assignment (fancy-cowork-js code scanning alert 1).
  PHP arrays have no prototype, so this leg was never exploitable; it refuses the same
  names (`cowork.invalid.intent` in `view` / `to` / `target`, `cowork.unidentified` for
  the sender) so the three legs agree on the re-pinned fixtures.

