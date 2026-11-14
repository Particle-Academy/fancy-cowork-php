# fancy-cowork-php code map

`particle-academy/fancy-cowork` (namespace `FancyCowork\`) is the PHP 8.4 / Laravel 13
leg of fancy-cowork (CC-fancy-0037): generic co-working sessions. The Node twin is
`@particle-academy/fancy-cowork` (repo `fancy-cowork-js`); the browser side is in
`@particle-academy/agent-integrations`. Only `illuminate/*` at runtime.

## Where things are

- `src/Engine/`: the pure pipeline, a line-for-line twin of fancy-cowork-js's
  `src/engine.ts`. `Engine::evaluateIntent` / `Join` / `Activate` / `Consent` / `End` /
  `Leave` / `Follow` return a `Result` (decision, state, events). `Pipeline` holds the ten
  defaults (`DEFAULT_MIDDLEWARE` order) and `resolve` / `run`; `Wire` validation, sizes and
  same-origin; `Config::resolve` the defaults; `SessionState` the array shape; `Reasons`
  every named refusal; `Glob` the `*` matcher.
- `src/CoworkManager.php` (singleton, facade `Cowork`): the host contracts (`identify`,
  `members`, `authorize`, `config`, `middleware`, `predicates`, `clock`) and the handlers
  (`create`, `sync`, `activate`, `join`, `consent`, `leave`, `end`, `follow`, `intent`,
  `authorizeChannel`, `channelMember`), mirroring fancy-cowork-js's `src/server.ts`.
- `src/Store/SessionStore.php`: state <-> `cowork_sessions` + `cowork_participants`, query
  builder only, row lock per session.
- `src/Http/CoworkController.php` + `routes/cowork.php`: thin HTTP face.
- `src/Events/*` + `src/Contracts/CoworkEvent.php`: the eight audit events.
  `src/Audit/AuditRecorder.php`: the optional `cowork_audit` writer.
- `src/GuestTokens.php`: optional signed guest-token `identify` resolver.
- `src/PusherSignature.php`: Pusher-protocol channel signature.
- `src/Broadcasting/`: the `fancy-pusher` broadcast driver. `PusherProtocol` (HTTP API
  request signature), `PusherHttpClient` (`POST /apps/{id}/events` over Laravel's HTTP
  client: 10 KB limit, 100-channel chunks, sender socket excluded), `PusherBroadcaster`
  (Laravel's Pusher conventions). Registered in `CoworkServiceProvider` before the channel
  callbacks resolve. The client keeps ONE Guzzle handler for its lifetime (keep-alive;
  `PendingRequest::setHandler`, so `Http::fake` still intercepts).
  **laravel/reverb and pusher/pusher-php-server are refused** (owner,
  q_63a8ab0c); never add them, any Pusher-protocol server works through this driver.
- Presence intents `cursor` / `activity` (`Wire::PRESENCE_KINDS`): server-routed
  presence for socket servers that do not stamp senders (Sockudo). Validated in
  `Wire::validIntent`; a cursor spends only `rate.cursorsPerSecond` (`Pipeline::rateLimit`,
  committed in `Engine::evaluateIntent`), never the intent budget.
- `src/Console/DoctorCommand.php`: `cowork:doctor`.
- `conformance/cowork-v1.json`: the shared fixtures, byte-identical with fancy-cowork-js
  and agent-integrations, pinned by SHA-256 in `tests/Support/Fixtures.php`.
- `tests/sabotage.php`: breaks one clause at a time; every mutation must be caught.

## Invariants a change must keep

- **Every unbound contract denies**, with its named reason. The `authorize` middleware can
  be removed but never configured to allow.
- **No host model.** Participants are opaque refs. Nothing reads a user, org or role;
  `tests/Unit/NoHostModelTest.php` fails on any reference to one.
- **Only `true` or `['allow' => true]` allows** from a resolver or middleware.
- **Committed state changes only on a whole-pipeline allow**; events and broadcasts happen
  only after the session row's transaction commits.
- **The server stamps the sender** from `identify`; no body field sets `from`.
- **No event or audit row carries an intent's value or an app payload.**
- **The fixtures decide.** A behaviour change is a fixture change made in all three repos
  at once, the SHA-256 re-pinned in each. The contract is section 11 of
  `.ai/plans/human-cobrowse-and-chat.md` in the envelope.
- **Known divergences from the JS leg:** string lengths are counted in code points here
  and in UTF-16 units there (they differ only above U+FFFF); a non-ref `follow` target is
  `422 cowork.invalid.intent` here; the PHP leg signs channel auth itself (`POST
  {prefix}/auth`), where the JS leg returns the member payload for the host to sign.

## Commands

```bash
composer install
vendor/bin/pest
php tests/sabotage.php
php tests/lint.php
composer validate --strict
```
