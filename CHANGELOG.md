# Changelog

Notable changes to `particle-academy/fancy-cowork`.

**Pre-1.0: breaking changes land in MINOR releases.** Read those entries before upgrading.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

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

