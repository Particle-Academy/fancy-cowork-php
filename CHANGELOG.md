# Changelog

Notable changes to `particle-academy/fancy-cowork`.

**Pre-1.0: breaking changes land in MINOR releases.** Read those entries before upgrading.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

---

## [Unreleased]

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

