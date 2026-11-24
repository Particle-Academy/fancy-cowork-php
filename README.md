# particle-academy/fancy-cowork

Generic co-working sessions for Laravel. Several people (and agents) share live UI:
one person drives another's view, several share one space, a support rep takes over an
anonymous visitor's request, or editors work on different pages of one document at the
same time. Peers exchange **intents** (visit, scroll, field, focus, click, submit,
control), never the DOM, so each peer runs its own app under its own server session.

This is the PHP leg. The Node twin is
[`@particle-academy/fancy-cowork`](https://github.com/Particle-Academy/fancy-cowork-js)
(same contracts, same wire contract, held together by the shared fixtures in
`conformance/cowork-v1.json`). The browser side (`useCoworkSession`, presence, cursors,
the session indicator) is in
[`@particle-academy/agent-integrations`](https://github.com/Particle-Academy/agent-integrations).

**Pre-1.0.** Breaking changes land in minor releases; read the changelog before upgrading.

<!-- fancy-agents-note:start -->
> **For agents.** This package is not listed on the Fancy MCP catalog server
> yet. The server, `https://ui.particle.academy/mcp` (streamable HTTP, no API key; MCP id
> `fancy-ui`), lists the released Fancy packages with install commands and
> docs. How an agent installs and uses this package is in [AGENTS.md](https://github.com/Particle-Academy/fancy-cowork-php/blob/main/AGENTS.md).
<!-- fancy-agents-note:end -->

## What it is not

- **Not chat.** There are no conversations, messages, contact lists or message storage.
  A host that wants its own messages carried over a session gets one opaque `app` intent
  (`topic` + `payload`), size- and rate-limited, which the package never stores, renders
  or interprets.
- **Not a user model.** There is no user, org or role anywhere in the package. A
  participant is an opaque string ref your resolvers hand it. No table has a foreign key
  to any of yours.
- **Not allowed by default.** Every host contract starts unbound, and an unbound contract
  refuses with a named reason (`cowork.unconfigured.identity`, ...). A fresh install does
  nothing until you decide who may do what. `php artisan cowork:doctor` lists what is
  unbound.

## Install

```bash
composer require particle-academy/fancy-cowork
php artisan migrate
php artisan cowork:doctor     # lists every unbound contract; exits 1 until all are bound
```

PHP 8.4+, Laravel 13. No third-party runtime dependencies: only `illuminate/*`.

## Bind the host contracts

In a service provider's `boot()`:

```php
use FancyCowork\Facades\Cowork;
use FancyCowork\Participant;
use FancyCowork\Session;
use Illuminate\Http\Request;

Cowork::identify(function (Request $request): ?Participant {
    $account = $request->user();                       // YOUR auth, whatever it is
    if (! $account) {
        return null;                                  // 401 cowork.unidentified
    }

    return new Participant(
        ref: 'member:'.$account->getKey(),
        label: $account->name,
    );
});
```

### `ref` is the person at the keyboard; `actingFor` is the account

**A participant is its `ref`.** Two browsers that identify with the same `ref` are the same
participant (the same person in two tabs). So when your own auth runs one person inside
another's account, the `ref` must still be the PERSON, and `actingFor` the ACCOUNT:

```php
// An admin viewing the app as user 1. Your session knows who really signed in.
Cowork::identify(function (Request $request): ?Participant {
    $account = $request->user();                         // user 1, while your auth runs the admin inside it
    if (! $account) {
        return null;
    }
    $realPerson = session('signed_in_as_admin_id');      // whatever YOUR auth records

    return $realPerson !== null
        ? new Participant(ref: 'admin:'.$realPerson, label: 'Support', actingFor: 'user:'.$account->getKey())
        : new Participant(ref: 'user:'.$account->getKey(), label: $account->name);
});
```

Returning the account (`user:1`) as `ref` for the admin made both browsers ONE participant
before 0.4: one person shown, every edit from the other browser skipped as the receiver's
own, and nobody ranked above anybody. **It is now refused:** a `join`, intent or heartbeat
that presents a live participant's `ref` with a different `actingFor` (none counts as a
value) answers `409 cowork.denied.ref-in-use`, and nothing is merged. The package records
`actingFor` on every event and never interprets it.

```php

Cowork::members(fn (Session $session, Participant $p): bool => /* may $p be in $session? */ true);

Cowork::authorize(function (Participant $p, string $ability, Session $session, ?array $intent): bool|array {
    // abilities: start, request, activate, join, end, follow, drive, intent:<kind>, view:<ref>
    return in_array($ability, ['start', 'join', 'end', 'intent:scroll', 'view:'.$p->ref], true);
});

Cowork::config(fn (Session $session): array => [
    'consent' => 'notify',                            // none | notify | accept
    'control' => ['mode' => 'baton'],                 // free | ranked | baton
    'routing' => 'server',                            // server | direct
    'limits' => ['mask' => ['card.*'], 'viewOnly' => ['terms.*']],
]);
```

**Only a literal `true` (or `['allow' => true]`) allows.** Anything else, including a
truthy `1` or `'yes'`, refuses. Return `['allow' => false, 'reason' => 'yours.reason']` to
name your own refusal. A resolver that throws answers `500 cowork.host.error` and nothing
is written.

### Config

Anything the config resolver omits takes the default; every limit is configurable.

| Key | Default | |
|---|---|---|
| `consent` | `notify` | `none` admits at once; `notify` admits at once and sets the indicator flag; `accept` refuses every join but the subject's until the subject consents |
| `subject` | the starter | whose consent `accept` waits for |
| `control` | `['mode' => 'baton']` | `free` (last writer, revision-guarded), `ranked` (`rank` map or `fn (string $ref): int`, `idleMs` 3000, `scope` `view` (default) or `session`: the mode for mirrored co-work, where a higher rank's intent on ANY view blocks lower ranks everywhere), `baton` (request / grant / release / revoke) |
| `routing` | `server` | see below |
| `limits` | none | `allow` / `deny` rules `{kinds, handles, routes, when}` (deny wins), `mask`, `viewOnly` handle globs |
| `rate` | 10 intents/s, 1 visit/s, 30 app/min, 5 starts/min | per sender |
| `size` | 8 KB per intent, 16 KB per app payload | |
| `maxParticipants` | 16 | |

`when` names a predicate: `Cowork::predicates(['afterHours' => fn ($ctx, array $intent): bool => ...])`.
A `when` naming a predicate you never defined fails closed.

### Middleware

One contract, `(PipelineContext $ctx, array $intent, Closure $next): array`, as a closure
or a class implementing `FancyCowork\Contracts\IntentMiddleware`. The shipped defaults, in
order, each removable by leaving it out:

`membership`, `authorize`, `payloadSize`, `sameOrigin`, `masking`, `limits`, `revision`,
`control`, `rateLimit`, `audit`.

```php
Cowork::middleware(fn (array $defaults): array => [MyTenantFence::class, ...$defaults, MyContentFilter::class]);
```

`authorize` cannot be configured to allow by default. It can only be removed, explicitly.
An unknown default name throws when you set the stack.

## Routes

Under `fancy-cowork.prefix` (`cowork`), behind `fancy-cowork.middleware` (`['web']`):

| | |
|---|---|
| `POST sessions` | `{mode: start\|request, route?}`: start a live session (ability `start`) or a requested one (ability `request`); 201 |
| `GET sessions/{id}` | re-sync after a reconnect: state, revision, views, control |
| `POST sessions/{id}/activate` | a requested session goes live (ability `activate`); the requester hears on `private-cowork.participant.{ref}` |
| `POST sessions/{id}/join` / `leave` / `consent` / `end` | |
| `POST sessions/{id}/follow` | `{target: ref\|null}` (ability `follow`) |
| `POST sessions/{id}/intents` | `{intent, view?, revision?, to?, id?}`: the pipeline, then a broadcast to everyone else; 202 |
| `POST sessions/{id}/heartbeat` | a live participant (same `ref`, same `actingFor`) is still here; 204. The client sends one every 30 s |
| `POST auth` | channel auth: `{socket_id, channel_name}`, signed for the default broadcaster |

Refusals are `{reason, blockedUntil?}` with 401 / 403 / 404 / 409 / 410 / 413 / 422 / 429 /
500. Every response is `Cache-Control: no-store`.

## Routing: server or direct

- **server**: intents go to `POST sessions/{id}/intents`, the pipeline runs on the server,
  and the intent is broadcast on `presence-cowork.session.{id}` to everyone but the sender's
  socket (`X-Socket-ID`). The sender is always the one `identify` returned; nothing in a
  body can set it.
- **direct**: intents travel peer to peer over presence whispers (the socket server must
  allow client events). The same pipeline runs at the sender and again
  at every receiver, in the browser (agent-integrations), because there is no server in
  between.

Channels: `presence-cowork.session.{id}` (identify + `members()` + a live participant; the
member payload comes from the server) and `private-cowork.participant.{ref}` (only that
ref). **Authorize them with `POST {prefix}/auth`** (default `/cowork/auth`; point Echo's
authorizer at it for `cowork.*` channels). It asks your `identify`, not a Laravel guard,
so it works for anonymous participants too, and it signs each presence member with
`user_id` = the participant's co-work **ref**: one member per person.

Laravel's own `/broadcasting/auth` also gets these channels registered
(`fancy-cowork.channels`), but it is **not equivalent**: Laravel always sets a presence
member's `user_id` to the authenticated ACCOUNT (`getAuthIdentifier()`). When two people
share one account (an admin viewing as someone, "View As"), the presence server merges
their connections into one member, and `here` / `joining` / `leaving` describe the wrong
person. Intents are unaffected (the server stamps their sender from `identify`), but
presence and `online` are wrong, and agent-integrations 0.65.2+ warns on the console.
Two tabs of the SAME person share one ref and one member, which is correct.

## Presence over the server (cursors and field activity)

Cursors and "this field is being edited" indicators are presence, not intents a view
acts on. They can travel two ways, chosen in the browser (agent-integrations'
`presenceRouting`):

- **direct**: as client events (whispers). Only safe when the socket server stamps the
  sender on presence-channel client events, as Reverb and Pusher do.
- **server**: as `cursor` and `activity` intents posted to `POST sessions/{id}/intents`.
  The server stamps the sender from `identify` and broadcasts to everyone else.
  **Sockudo, and so Genie's WebSockets service, needs `server`:** it delivers client
  events without the sender (`user_id: None`), and the browser drops what it cannot
  attribute.

```json
{ "intent": { "kind": "cursor", "route": "/doc/1", "anchor": "title", "fx": 0.5, "fy": 0.2 } }
{ "intent": { "kind": "cursor", "route": "/doc/1", "x": 120, "y": 640 } }
{ "intent": { "kind": "activity", "handle": "card.number", "active": true } }
```

They run the same pipeline: `authorize` is asked `intent:cursor` / `intent:activity`,
and the size limit applies. They need no view or revision, are never subject to
limits, masking or control, and change no state. **A cursor spends only its own
bucket**, `rate.cursorsPerSecond` (default 10, host-configurable), so cursors never
starve the intents; `activity` spends the ordinary intent budget. An activity carries a
handle and never a value, so it is allowed even on a masked field.

## Broadcasting: any Pusher-protocol server, no Pusher SDK

Server routing publishes through the package's own `fancy-pusher` broadcast driver: an
HMAC-signed `POST /apps/{app_id}/events` over Laravel's HTTP client, plus Laravel's
`/broadcasting/auth` signatures. It works with any Pusher-protocol server (Genie's
WebSockets service, Sockudo, Reverb, Pusher), and needs neither `pusher/pusher-php-server`
nor `laravel/reverb`. Point your existing Pusher- or Reverb-shaped connection at it:

```php
// config/broadcasting.php
'reverb' => [
    'driver' => 'fancy-pusher',
    'key' => env('REVERB_APP_KEY'),
    'secret' => env('REVERB_APP_SECRET'),
    'app_id' => env('REVERB_APP_ID'),
    'options' => [
        'host' => env('REVERB_HOST'),
        'port' => env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
    ],
],
```

A host already running Reverb keeps working either way: it is the same protocol. The
driver refuses a payload over the protocol's 10 KB limit before sending it, sends at
most 100 channels per request, excludes the sender's socket, and raises
`BroadcastException` on a non-2xx answer, never with the secret in it. A `429` (the
events API rate-limiting, as Genie's WebSockets service does), a `500`/`502`/`503`/`504`
or a connection that fails is retried after its `Retry-After`, or else after 250 ms
doubled per retry; by default at most twice and never more than 3 s of waiting in total
(`fancy-cowork.publish.retries`, `retry_budget_ms`, `backoff_ms`). A broadcast still
undelivered after that is never dropped silently: it is logged as a warning and
dispatched as `FancyCowork\Broadcasting\BroadcastFailed` (channel, event, session,
reason; never the payload). Committed session changes reach a client that missed them
on its next `GET`; a missed cursor or activity is stale until that peer's next one. The body is
ASCII-only JSON, because some servers read it as Latin-1. Laravel's HTTP client needs
Guzzle, which every `laravel/framework` app already has.

**Connections are reused.** The driver keeps ONE Guzzle handler for its lifetime, so a
publish after the first in the same PHP process skips the TLS handshake. Measured
against Genie's WebSockets service (`https://websockets.gen`, 20 publishes, twice): a
fresh handler per publish took p50 1402 to 1463 ms; the reused handler p50 2.0 to
2.5 ms and p95 2.8 to 3.4 ms. The FIRST publish in a process still pays the handshake
(about 1.1 to 1.8 s there), so the gain lands in long-lived processes (queue workers,
Octane, a request that broadcasts more than once). Under plain PHP-FPM each request
starts fresh, and PHP 8.4 has no persistent cURL share handle to carry a connection
across requests.

## Live presence and abandoned sessions

A closed browser never says goodbye, so the server does not wait for one:

- every request from a live participant (same `ref`, same `actingFor`), and every
  `POST sessions/{id}/heartbeat`, refreshes that participant's `lastSeen`;
- a participant not seen for `presence.idle_ms` is removed, as if it had left (`PeerLeft`
  with `reason: expired`; any baton it held goes back);
- when the starter is gone the session ends (`SessionEnded`, `reason: starter-left`),
  unless `presence.end_when_starter_leaves` is false;
- a session with nobody in it for `presence.empty_ms` ends (`reason: empty`).

This runs on every read and write of a session, inside its row lock, so it needs no
scheduler. For sessions nobody touches, schedule the backstop:

```php
$schedule->command('cowork:prune')->everyMinute();
```

```php
// config/fancy-cowork.php -- secure defaults, every one yours to change
'presence' => [
    'idle_ms' => 120_000,                // 2 min without a heartbeat or a request
    'empty_ms' => 600_000,               // 10 min with nobody in the session
    'end_when_starter_leaves' => true,
],
```

`GET sessions/{id}` gives each participant `lastSeen` and `online`. The sweep runs before
the answer, so every participant listed is online by construction; `lastSeen` is the
information. Once a session has ended, `session.endReason` says why: `ended` (an
explicit end), `starter-left` or `empty`; it is `null` while the session is live.

## Audit

Events, always: `SessionRequested`, `SessionActivated`, `PeerJoined`, `PeerLeft`,
`ConsentGiven`, `ControlChanged`, `IntentRelayed`, `SessionEnded`, each carrying `ref` and
`actingFor`. Listen on `FancyCowork\Contracts\CoworkEvent` for all of them. No event
carries an intent's value or an app payload.

**Optional:** set `fancy-cowork.audit.table` to `true` and run the migration in
`database/migrations/audit` to have every event written to `cowork_audit`. Off by default.

## Optional: signed guest tokens

For the anonymous support case. The package has no guest concept; this is one `identify`
resolver you may bind, or ignore.

```php
use FancyCowork\GuestTokens;

$token = app(GuestTokens::class)->issue();            // give it to the visitor's page

Cowork::identify(app(GuestTokens::class)->resolver(
    fallback: fn (Request $r) => /* your signed-in participants */ null,
));
```

A token is HMAC-SHA256-signed with a key derived from `app.key`, compared in constant
time, expires after `fancy-cowork.guest.ttl` seconds (7200), is read from the
`X-Cowork-Guest` header, and resolves to the ref `guest:<id>`. It proves the token came
from your app and nothing else about the visitor.

## Security model

**Default deny is the main control.** Masking is applied at the sender: a masked value
never leaves the browser. Receivers and the server also refuse `field` into masked or
view-only handles.

A malicious participant can see every unmasked thing on a view it is allowed to see, act
within what your policy grants (in `free` mode, fight for fields), and send `app` intents
within the limits. It cannot read a masked value, act in a view-only region, make another
participant perform a side effect, send anyone off-origin, claim another ref, join without
passing `members()`, stay after the session ends, or reach your `app` handler with an
oversized or rate-exceeding payload.

**Residual risks:** in `direct` mode, enforcement is only as good as the receiver's
pipeline, and a peer can forge frames under its own socket. `app` payloads are untrusted
data for you to validate. In `free` control there is only last-writer-wins. Masking hides
values from peers; it does not hide them from someone who can load the page under the same
account.

## Testing

```bash
composer install
vendor/bin/pest           # includes every case in conformance/cowork-v1.json
php tests/sabotage.php    # breaks one clause at a time; every mutation must be caught
php tests/lint.php
```

## Licence

MIT.
