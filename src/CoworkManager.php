<?php

declare(strict_types=1);

namespace FancyCowork;

use Closure;
use FancyCowork\Broadcasting\BroadcastFailed;
use FancyCowork\Engine\Config;
use FancyCowork\Engine\Engine;
use FancyCowork\Engine\EngineHost;
use FancyCowork\Engine\Pipeline;
use FancyCowork\Engine\Reasons;
use FancyCowork\Engine\Result;
use FancyCowork\Engine\SessionState;
use FancyCowork\Engine\Wire;
use FancyCowork\Events\CoworkEventBase;
use FancyCowork\Store\SessionStore;
use FancyCowork\Support\Ulid;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * The host contracts and the server handlers. Every contract is optional to
 * BIND and mandatory to WORK: an unbound one refuses with
 * `cowork.unconfigured.<name>`, so a fresh install does nothing at all until
 * the host decides who may do what. Reached through the `Cowork` facade.
 *
 *     Cowork::identify(fn (Request $r): ?Participant => ...)   // who is this request
 *         ->members(fn (Session $s, Participant $p): bool => ...)
 *         ->authorize(fn (Participant $p, string $ability, Session $s, ?array $intent): bool|array => ...)
 *         ->config(fn (Session $s): array => [...])            // consent, control, routing, limits
 *         ->middleware(fn (array $defaults): array => [...$defaults, MyCheck::class]);
 *
 * Only a literal `true` (or `['allow' => true]`) from a resolver allows.
 * Abilities asked: start, request, activate, join, end, follow, drive,
 * intent:<kind>, view:<ref>.
 */
final class CoworkManager
{
    private ?Closure $identify = null;

    private ?Closure $members = null;

    private ?Closure $authorize = null;

    private ?Closure $config = null;

    /** @var list<mixed> */
    private array $stack = Pipeline::DEFAULT_MIDDLEWARE;

    /** @var array<string, Closure> */
    private array $predicates = [];

    private ?Closure $clock = null;

    private const string SESSION_ID = '/^[A-Za-z0-9_-]{1,64}$/D';

    private const string SESSION_CHANNEL = '/^presence-cowork\.session\.([A-Za-z0-9_-]{1,64})$/D';

    private const string PARTICIPANT_CHANNEL = '/^private-cowork\.participant\.(.{1,256})$/sD';

    public function __construct(
        private readonly Container $container,
        private readonly SessionStore $store,
        private readonly Dispatcher $events,
        private readonly ConfigRepository $settings,
    ) {}

    // -------------------------------------------------------------------
    // Host contracts
    // -------------------------------------------------------------------

    /** @param  Closure(Request): (Participant|array<string, mixed>|null)  $resolver */
    public function identify(Closure $resolver): self
    {
        $this->identify = $resolver;

        return $this;
    }

    /** @param  Closure(Session, Participant): bool  $resolver */
    public function members(Closure $resolver): self
    {
        $this->members = $resolver;

        return $this;
    }

    /** @param  Closure(Participant, string, Session, ?array<string, mixed>): (bool|array{allow: bool, reason?: string})  $policy */
    public function authorize(Closure $policy): self
    {
        $this->authorize = $policy;

        return $this;
    }

    /** @param  Closure(Session): array<string, mixed>  $resolver */
    public function config(Closure $resolver): self
    {
        $this->config = $resolver;

        return $this;
    }

    /**
     * The intent middleware stack: default names, closures, IntentMiddleware
     * instances or class names, in order. Or a closure given the defaults.
     * An unknown default name throws here, not at the first intent.
     *
     * @param  list<mixed>|(Closure(list<string>): list<mixed>)  $stack
     */
    public function middleware(array|Closure $stack): self
    {
        $entries = $stack instanceof Closure ? $stack(Pipeline::DEFAULT_MIDDLEWARE) : $stack;
        $resolved = array_map(fn (mixed $entry): mixed => is_string($entry) && ! in_array($entry, Pipeline::DEFAULT_MIDDLEWARE, true) && class_exists($entry)
            ? $this->container->make($entry)
            : $entry, array_values($entries));
        Pipeline::resolve($resolved);
        $this->stack = $resolved;

        return $this;
    }

    /**
     * Named predicates for a limits rule's `when`. A name with no predicate fails closed.
     *
     * @param  array<string, Closure>  $predicates
     */
    public function predicates(array $predicates): self
    {
        $this->predicates = $predicates;

        return $this;
    }

    /** The millisecond clock (tests and replays). */
    public function clock(Closure $clock): self
    {
        $this->clock = $clock;

        return $this;
    }

    /** @return array<string, bool> contract => bound */
    public function contracts(): array
    {
        return [
            'identify' => $this->identify !== null,
            'members' => $this->members !== null,
            'authorize' => $this->authorize !== null,
            'config' => $this->config !== null,
        ];
    }

    /** @return list<string> the stack, with host entries shown as `host` */
    public function stackNames(): array
    {
        return array_map(static fn (mixed $entry): string => is_string($entry) ? $entry : 'host', $this->stack);
    }

    // -------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------

    /** @param  array<string, mixed>  $body  `mode`: start | request, `route` */
    public function create(Request $request, array $body): CoworkResponse
    {
        return $this->guarded(function () use ($request, $body): CoworkResponse {
            $who = $this->identifyOrRefuse($request);
            if ($who instanceof CoworkResponse) {
                return $who;
            }
            if ($this->config === null) {
                return CoworkResponse::refuse(403, Reasons::UNCONFIGURED_CONFIG);
            }
            $mode = $body['mode'] ?? 'start';
            if (! in_array($mode, ['start', 'request'], true)) {
                return CoworkResponse::refuse(422, 'cowork.invalid.request');
            }
            $at = $this->now();
            $draft = SessionState::create(Ulid::generate($at), $mode === 'start' ? 'live' : 'requested', $who->ref, [], $at);
            $session = Session::fromState($draft);
            $config = $this->resolveConfig($session);
            if ($this->members === null) {
                return CoworkResponse::refuse(403, Reasons::UNCONFIGURED_MEMBERS);
            }
            if (($this->members)($session, $who) !== true) {
                return CoworkResponse::refuse(403, Reasons::MEMBERSHIP);
            }
            if ($this->authorize === null) {
                return CoworkResponse::refuse(403, Reasons::UNCONFIGURED_AUTHORIZE);
            }
            $answer = ($this->authorize)($who, $mode, $session, null);
            if (! ($answer === true || (is_array($answer) && ($answer['allow'] ?? null) === true))) {
                $reason = is_array($answer) && is_string($answer['reason'] ?? null) && $answer['reason'] !== '' ? $answer['reason'] : Reasons::AUTHORIZE;

                return CoworkResponse::refuse(403, $reason);
            }
            if ($this->store->countStarts($who->ref, $at - 60_000) >= $config['rate']['startsPerMinute']) {
                return CoworkResponse::refuse(429, Reasons::RATE);
            }
            $participant = ['ref' => $who->ref];
            foreach (['label' => $who->label, 'actingFor' => $who->actingFor] as $key => $value) {
                if ($value !== null) {
                    $participant[$key] = $value;
                }
            }
            if (is_string($body['route'] ?? null)) {
                $participant['route'] = $body['route'];
            }
            $state = SessionState::create($draft['id'], $draft['state'], $who->ref, [$participant], $at);
            if ($who->attributes !== null) {
                $state['participants'][$who->ref]['attributes'] = $who->attributes;
            }
            $this->store->write($state, self::snapshot($config), $at);
            $events = [];
            $actor = $who->actingFor !== null ? ['actingFor' => $who->actingFor] : [];
            if ($mode === 'request') {
                $events[] = ['type' => 'SessionRequested', 'session' => $state['id'], 'ref' => $who->ref, ...$actor, 'at' => $at];
            }
            $events[] = ['type' => 'PeerJoined', 'session' => $state['id'], 'ref' => $who->ref, ...$actor, 'at' => $at];
            $this->emit($events);

            return new CoworkResponse(201, ['session' => self::publicSession($state, $config)]);
        });
    }

    public function sync(Request $request, string $id): CoworkResponse
    {
        return $this->guarded(function () use ($request, $id): CoworkResponse {
            $who = $this->identifyOrRefuse($request);
            if ($who instanceof CoworkResponse) {
                return $who;
            }
            if ($this->config === null) {
                return CoworkResponse::refuse(403, Reasons::UNCONFIGURED_CONFIG);
            }
            $state = preg_match(self::SESSION_ID, $id) === 1 ? $this->store->read($id) : null;
            if ($state === null) {
                return CoworkResponse::refuse(404, Reasons::UNKNOWN_SESSION);
            }
            if ($this->members === null) {
                return CoworkResponse::refuse(403, Reasons::UNCONFIGURED_MEMBERS);
            }
            $session = Session::fromState($state);
            if (($this->members)($session, $who) !== true) {
                return CoworkResponse::refuse(403, Reasons::MEMBERSHIP);
            }
            $config = $this->resolveConfig($session);
            [$state, $events] = $this->store->locked($id, function () use ($id, $who, $config): array {
                $original = $this->store->read($id);
                if ($original === null) {
                    return [null, []];
                }
                $now = $this->now();
                $swept = $this->presence()->expire(Presence::touch($original, $who->ref, $who->actingFor, $now), $now, $this->store->lastLeftAt($id));
                if ($swept['state'] !== $original) {
                    $this->store->write($swept['state'], self::snapshot($config), $now);
                }

                return [$swept['state'], $swept['events']];
            });
            $this->emit($events);
            if ($state === null) {
                return CoworkResponse::refuse(404, Reasons::UNKNOWN_SESSION);
            }

            return new CoworkResponse(200, ['session' => self::publicSession($state, $config, $this->presence(), $this->now())]);
        });
    }

    /** @param  array<string, mixed>  $body */
    public function activate(Request $request, string $id, array $body = []): CoworkResponse
    {
        return $this->onSession($request, $id, fn (EngineHost $host, array $state, Participant $who): Result => Engine::evaluateActivate($host, $state, $this->opInput($who, $body['route'] ?? null)),
            function (Result $result, array $config): CoworkResponse {
                $this->broadcast('private-cowork.participant.'.$result->state['starter'], 'cowork.activated', ['session' => $result->state['id']]);
                $this->broadcast('presence-cowork.session.'.$result->state['id'], 'cowork.session', self::publicSession($result->state, $config));

                return new CoworkResponse(200, ['session' => self::publicSession($result->state, $config)]);
            });
    }

    /** @param  array<string, mixed>  $body */
    public function join(Request $request, string $id, array $body = []): CoworkResponse
    {
        return $this->onSession($request, $id, fn (EngineHost $host, array $state, Participant $who): Result => Engine::evaluateJoin($host, $state, $this->opInput($who, $body['route'] ?? null)));
    }

    public function consent(Request $request, string $id): CoworkResponse
    {
        return $this->onSession($request, $id, fn (EngineHost $host, array $state, Participant $who): Result => Engine::evaluateConsent($host, $state, $this->opInput($who)));
    }

    public function leave(Request $request, string $id): CoworkResponse
    {
        return $this->onSession($request, $id, fn (EngineHost $host, array $state, Participant $who): Result => Engine::evaluateLeave($host, $state, $this->opInput($who)), null, needsConfig: false);
    }

    /**
     * Live presence: a participant (same ref, same `actingFor`) says it is still
     * here. 204. Anyone idle for `presence.idle_ms` is removed on the way, by the
     * same sweep every request runs.
     */
    public function heartbeat(Request $request, string $id): CoworkResponse
    {
        return $this->onSession($request, $id, static function (EngineHost $host, array $state, Participant $who): Result {
            if ($state['state'] === 'ended') {
                return new Result(Reasons::refuse(Reasons::ENDED, 410), $state, []);
            }
            $participant = $state['participants'][$who->ref] ?? null;
            if ($participant === null || ($host->members !== null && ($host->members)($who->ref) !== true)) {
                return new Result(Reasons::refuse(Reasons::MEMBERSHIP, 403), $state, []);
            }
            if (! SessionState::sameIdentity($participant, $who->actingFor)) {
                return new Result(Reasons::refuse(Reasons::REF_IN_USE, 409), $state, []);
            }

            return new Result(Reasons::allow(), $state, []);
        }, static fn (): CoworkResponse => new CoworkResponse(204, []), needsConfig: false);
    }

    /**
     * Expire one session's idle participants and end it if abandoned, with no
     * request in hand: what `cowork:prune` runs for every open session.
     *
     * @return array{expired: int, ended: bool}
     */
    public function prune(string $id): array
    {
        [$counts, $events] = $this->store->locked($id, function () use ($id): array {
            $state = $this->store->read($id);
            if ($state === null) {
                return [['expired' => 0, 'ended' => false], []];
            }
            $now = $this->now();
            $swept = $this->presence()->expire($state, $now, $this->store->lastLeftAt($id));
            if ($swept['state'] !== $state) {
                $this->store->write($swept['state'], self::snapshot($this->configOrDefault($state)), $now);
            }

            return [['expired' => $swept['expired'], 'ended' => $swept['ended']], $swept['events']];
        });
        $this->emit($events);

        return $counts;
    }

    public function end(Request $request, string $id): CoworkResponse
    {
        return $this->onSession($request, $id, fn (EngineHost $host, array $state, Participant $who): Result => Engine::evaluateEnd($host, $state, $this->opInput($who)));
    }

    /** @param  array<string, mixed>  $body  `target`: a ref, or null to stop following */
    public function follow(Request $request, string $id, array $body): CoworkResponse
    {
        return $this->onSession($request, $id, fn (EngineHost $host, array $state, Participant $who): Result => Engine::evaluateFollow($host, $state, [...$this->opInput($who), 'target' => $body['target'] ?? null]));
    }

    /**
     * Server routing: run the pipeline, then relay to everyone else on the
     * presence channel. The sender comes from `identify`; nothing in the body
     * can set it.
     *
     * @param  array<string, mixed>  $body  intent, view, revision, to, id, socketId
     */
    public function intent(Request $request, string $id, array $body): CoworkResponse
    {
        $envelope = [];

        return $this->onSession($request, $id, function (EngineHost $host, array $state, Participant $who) use ($body, &$envelope): Result {
            $at = $this->now();
            $envelope = [
                'id' => is_string($body['id'] ?? null) && preg_match(self::SESSION_ID, $body['id']) === 1 ? $body['id'] : Ulid::generate($at),
                'session' => $state['id'],
                'from' => $who->ref,
            ];
            foreach (['to', 'view', 'revision'] as $key) {
                if (array_key_exists($key, $body)) {
                    $envelope[$key] = $body[$key];
                }
            }
            $envelope['at'] = $at;

            return Engine::evaluateIntent($host, $state, [
                'from' => $who->ref,
                'actingFor' => $who->actingFor,
                'envelope' => $envelope,
                'intent' => $body['intent'] ?? null,
                'now' => $at,
            ]);
        }, function (Result $result) use ($request, $id, $body, &$envelope): CoworkResponse {
            $socket = $request->header('X-Socket-ID') ?? ($body['socketId'] ?? null);
            $socket = is_string($socket) && preg_match('/^\d+\.\d+$/D', $socket) === 1 ? $socket : null;
            $this->broadcast("presence-cowork.session.{$id}", 'cowork.intent', [...$envelope, 'intent' => $body['intent']], $socket);

            return new CoworkResponse(202, ['id' => $envelope['id'], 'revision' => $result->state['revision']]);
        });
    }

    /**
     * Channel auth for `presence-cowork.session.{id}` (identify + members + a
     * live participant; the member payload comes from the server) and
     * `private-cowork.participant.{ref}` (only that ref), signed for the default
     * broadcaster. Works for anonymous participants too: it asks `identify`,
     * never a Laravel guard.
     */
    public function authorizeChannel(Request $request, mixed $socketId, mixed $channel): CoworkResponse
    {
        return $this->guarded(function () use ($request, $socketId, $channel): CoworkResponse {
            $who = $this->identifyOrRefuse($request);
            if ($who instanceof CoworkResponse) {
                return $who;
            }
            if (! is_string($socketId) || preg_match('/^\d+\.\d+$/D', $socketId) !== 1 || ! is_string($channel)) {
                return CoworkResponse::refuse(422, 'cowork.invalid.request');
            }
            $decision = $this->channelDecision($who, $channel);
            if ($decision instanceof CoworkResponse) {
                return $decision;
            }
            $connection = (string) $this->settings->get('broadcasting.default');
            $key = $this->settings->get("broadcasting.connections.{$connection}.key");
            $secret = $this->settings->get("broadcasting.connections.{$connection}.secret");
            if (! is_string($key) || $key === '' || ! is_string($secret) || $secret === '') {
                return CoworkResponse::refuse(503, 'cowork.unconfigured.broadcasting');
            }
            $data = $decision === true ? null : json_encode($decision, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return new CoworkResponse(200, PusherSignature::sign($key, $secret, $socketId, $channel, $data));
        });
    }

    /**
     * For Laravel's own `/broadcasting/auth`: the presence member info, `true`
     * for the participant's private channel, or `false`. Never throws.
     *
     * @return array<string, mixed>|bool
     */
    public function channelMember(Request $request, string $channel): array|bool
    {
        try {
            $who = $this->identifyOrRefuse($request);
            if ($who instanceof CoworkResponse) {
                return false;
            }
            $decision = $this->channelDecision($who, $channel);

            return match (true) {
                $decision === true => true,
                is_array($decision) => $decision['user_info'],
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    /** @return CoworkResponse|true|array{user_id: string, user_info: array<string, mixed>} */
    private function channelDecision(Participant $who, string $channel): CoworkResponse|bool|array
    {
        if (preg_match(self::PARTICIPANT_CHANNEL, $channel, $match) === 1) {
            return $match[1] === $who->ref ? true : CoworkResponse::refuse(403, Reasons::CHANNEL);
        }
        if (preg_match(self::SESSION_CHANNEL, $channel, $match) !== 1) {
            return CoworkResponse::refuse(403, Reasons::CHANNEL);
        }
        if ($this->config === null) {
            return CoworkResponse::refuse(403, Reasons::UNCONFIGURED_CONFIG);
        }
        $state = $this->store->read($match[1]);
        if ($state === null) {
            return CoworkResponse::refuse(404, Reasons::UNKNOWN_SESSION);
        }
        if ($state['state'] === 'ended') {
            return CoworkResponse::refuse(410, Reasons::ENDED);
        }
        if ($this->members === null) {
            return CoworkResponse::refuse(403, Reasons::UNCONFIGURED_MEMBERS);
        }
        $joined = $state['participants'][$who->ref] ?? null;
        if ($joined === null || ($this->members)(Session::fromState($state), $who) !== true) {
            return CoworkResponse::refuse(403, Reasons::MEMBERSHIP);
        }
        $info = ['ref' => (string) $joined['ref']];
        if (isset($joined['label'])) {
            $info['label'] = $joined['label'];
        }
        $info['view'] = (string) $joined['ref'];
        $info['route'] = $joined['route'];

        return ['user_id' => (string) $joined['ref'], 'user_info' => $info];
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * identify -> config bound -> load (locked) -> run -> write on allow -> emit.
     *
     * @param  Closure(EngineHost, array<string, mixed>, Participant): Result  $run
     * @param  (Closure(Result, array<string, mixed>): CoworkResponse)|null  $after
     */
    private function onSession(Request $request, string $id, Closure $run, ?Closure $after = null, bool $needsConfig = true): CoworkResponse
    {
        return $this->guarded(function () use ($request, $id, $run, $after, $needsConfig): CoworkResponse {
            $who = $this->identifyOrRefuse($request);
            if ($who instanceof CoworkResponse) {
                return $who;
            }
            if ($needsConfig && $this->config === null) {
                return CoworkResponse::refuse(403, Reasons::UNCONFIGURED_CONFIG);
            }
            if (preg_match(self::SESSION_ID, $id) !== 1) {
                return CoworkResponse::refuse(404, Reasons::UNKNOWN_SESSION);
            }
            [$response, $events, $broadcast] = $this->store->locked($id, function () use ($id, $run, $who): array {
                $original = $this->store->read($id);
                if ($original === null) {
                    return [CoworkResponse::refuse(404, Reasons::UNKNOWN_SESSION), [], null];
                }
                // Presence first: the caller is alive (it is calling), then anyone
                // idle for presence.idle_ms is gone, and an abandoned session ends.
                $now = $this->now();
                $presence = $this->presence();
                $swept = $presence->expire(Presence::touch($original, $who->ref, $who->actingFor, $now), $now, $this->store->lastLeftAt($id));
                $state = $swept['state'];
                $events = $swept['events'];
                $host = $this->hostFor($state, $who);
                $config = $host->config ?? Config::resolve();
                $result = $run($host, $state, $who);
                if (! $result->allowed()) {
                    // The sweep is committed even when the operation is refused.
                    if ($state !== $original) {
                        $this->store->write($state, self::snapshot($config), $now);
                    }
                    $decision = $result->decision;
                    $extra = isset($decision['blockedUntil']) ? ['blockedUntil' => $decision['blockedUntil']] : [];

                    return [CoworkResponse::refuse($decision['status'], $decision['reason'], $extra), $events, null];
                }
                $next = Presence::touch($result->state, $who->ref, $who->actingFor, $now);
                $left = count($next['participants']) < count($state['participants']);
                $closed = $presence->endIfAbandoned($next, $now, $left ? $now : $this->store->lastLeftAt($id));
                $next = $closed['state'];
                if ($next !== $original) {
                    $this->store->write($next, self::snapshot($config), $now);
                }

                return [null, [...$events, ...$result->events, ...$closed['events']], [new Result($result->decision, $next, $result->events), $config, $next['revision'] !== $original['revision']]];
            });
            if ($response instanceof CoworkResponse) {
                $this->emit($events);

                return $response;
            }
            // Committed: emit and broadcast only after the transaction is in.
            $this->emit($events);
            [$result, $config, $changed] = $broadcast;
            if ($after !== null) {
                return $after($result, $config);
            }
            $public = self::publicSession($result->state, $config, $this->presence(), $this->now());
            // Nothing changed (the same person joining again, a presence touch): nothing to tell.
            if ($changed) {
                $this->broadcast("presence-cowork.session.{$id}", 'cowork.session', $public);
            }

            return new CoworkResponse(200, ['session' => $public]);
        });
    }

    /** @param  array<string, mixed>  $state */
    private function hostFor(array $state, Participant $caller): EngineHost
    {
        $session = Session::fromState($state);
        $config = $this->config !== null ? $this->resolveConfig($session) : null;
        $participantFor = static function (string $ref) use ($caller, $state): Participant {
            if ($ref === $caller->ref) {
                return $caller;
            }
            $stored = $state['participants'][$ref] ?? null;

            return $stored === null ? new Participant($ref) : new Participant($ref, $stored['label'] ?? null, $stored['attributes'] ?? null, $stored['actingFor'] ?? null);
        };
        $members = $this->members;
        $authorize = $this->authorize;
        $predicates = $this->predicates;

        return new EngineHost(
            identity: true,
            config: $config,
            members: $members === null ? null : static fn (string $ref): mixed => $members($session, $participantFor($ref)),
            authorize: $authorize === null ? null : static fn (string $ref, string $ability, ?array $intent): mixed => $authorize($participantFor($ref), $ability, $session, $intent),
            predicate: static fn (string $name, $ctx, array $intent): ?bool => isset($predicates[$name]) ? $predicates[$name]($ctx, $intent) === true : null,
            middleware: $this->stack,
        );
    }

    /** @return array<string, mixed> */
    private function resolveConfig(Session $session): array
    {
        $input = ($this->config)($session);
        if (! is_array($input)) {
            throw new InvalidArgumentException('A cowork config resolver must return an array.');
        }

        return Config::resolve($input);
    }

    private function identifyOrRefuse(Request $request): Participant|CoworkResponse
    {
        if ($this->identify === null) {
            return CoworkResponse::refuse(403, Reasons::UNCONFIGURED_IDENTITY);
        }
        $who = ($this->identify)($request);
        if (is_array($who)) {
            $who = Participant::fromArray($who);
        }
        if (! $who instanceof Participant || ! Wire::isRef($who->ref)) {
            return CoworkResponse::refuse(401, Reasons::UNIDENTIFIED);
        }

        return $who;
    }

    /** @return array<string, mixed> */
    private function opInput(Participant $who, mixed $route = null): array
    {
        $input = ['from' => $who->ref, 'now' => $this->now()];
        foreach (['actingFor' => $who->actingFor, 'label' => $who->label, 'attributes' => $who->attributes] as $key => $value) {
            if ($value !== null) {
                $input[$key] = $value;
            }
        }
        if (is_string($route)) {
            $input['route'] = $route;
        }

        return $input;
    }

    /** @param  Closure(): CoworkResponse  $work */
    private function guarded(Closure $work): CoworkResponse
    {
        try {
            return $work();
        } catch (Throwable) {
            return CoworkResponse::refuse(500, Reasons::HOST_ERROR);
        }
    }

    /** @param  list<array<string, mixed>>  $events */
    private function emit(array $events): void
    {
        foreach ($events as $event) {
            try {
                $this->events->dispatch(CoworkEventBase::fromEngine($event));
            } catch (Throwable) {
                // A host listener cannot undo a committed change.
            }
        }
    }

    /** @param  array<string, mixed>  $payload */
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: ?string}> */
    private array $pendingBroadcasts = [];

    private bool $flushRegistered = false;

    /**
     * Broadcast AFTER the response is sent (`fancy-cowork.broadcast_after_response`, default
     * true): a synchronous broadcaster talking to a slow or rate-limited socket server then
     * costs the broadcast, never the caller's request, and never holds the row lock (the
     * transaction is long committed). Flushed on the kernel's terminate; a long-running
     * process that is not a request (a queue worker) calls flushBroadcasts() itself.
     */
    private function broadcast(string $channel, string $event, array $payload, ?string $exceptSocket = null): void
    {
        if (! $this->settings->get('fancy-cowork.broadcast', true) || ! $this->container->bound(BroadcastFactory::class)) {
            return;
        }
        if (! $this->settings->get('fancy-cowork.broadcast_after_response', true) || ! method_exists($this->container, 'terminating')) {
            $this->publish($channel, $event, $payload, $exceptSocket);

            return;
        }
        $this->pendingBroadcasts[] = [$channel, $event, $payload, $exceptSocket];
        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            $this->container->terminating(fn () => $this->flushBroadcasts());
        }
    }

    /** Send every broadcast still waiting, in order, once each. */
    public function flushBroadcasts(): void
    {
        while ($this->pendingBroadcasts !== []) {
            [$channel, $event, $payload, $exceptSocket] = array_shift($this->pendingBroadcasts);
            $this->publish($channel, $event, $payload, $exceptSocket);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function publish(string $channel, string $event, array $payload, ?string $exceptSocket): void
    {
        try {
            $this->container->make(BroadcastFactory::class)->connection()->broadcast([$channel], $event, [...$payload, 'socket' => $exceptSocket]);
        } catch (Throwable $e) {
            // The change is committed; a client that missed it re-syncs with GET. But
            // say so: this runs after the response, so nothing else ever would.
            $this->reportUndelivered($channel, $event, $e);
        }
    }

    /**
     * A broadcast that could not be delivered: one warning in the log and a
     * {@see BroadcastFailed} event. Neither carries the payload. Neither may throw:
     * this runs after the response, where an exception reaches nobody.
     */
    private function reportUndelivered(string $channel, string $event, Throwable $e): void
    {
        // A BroadcastException is the broadcaster's own refusal, written to be read
        // (fancy-pusher's names the status, never the secret). Anything else may
        // carry a URL or a body, so only its class is kept.
        $reason = $e instanceof BroadcastException ? mb_substr($e->getMessage(), 0, 300) : $e::class;
        $session = preg_match('/^presence-cowork\.session\.([A-Za-z0-9_-]{1,64})$/D', $channel, $m) === 1 ? $m[1] : null;

        try {
            if ($this->container->bound('log')) {
                $this->container->make('log')->warning("fancy-cowork: `{$event}` on {$channel} was not delivered: {$reason}", [
                    'channel' => $channel, 'event' => $event, 'session' => $session,
                ]);
            }
        } catch (Throwable) {
            // A broken logger must not stop the signal below.
        }
        try {
            $this->events->dispatch(new BroadcastFailed($channel, $event, $session, $reason));
        } catch (Throwable) {
            // A listener that throws reaches nobody here either.
        }
    }

    private function presence(): Presence
    {
        $settings = $this->settings->get('fancy-cowork.presence', []);

        return Presence::fromConfig(is_array($settings) ? $settings : []);
    }

    /**
     * The session's config for a write that has no request: the host's when it is
     * bound and answers, the shipped defaults otherwise.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function configOrDefault(array $state): array
    {
        if ($this->config === null) {
            return Config::resolve();
        }
        try {
            return $this->resolveConfig(Session::fromState($state));
        } catch (Throwable) {
            return Config::resolve();
        }
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : (int) floor(microtime(true) * 1000);
    }

    /**
     * What a participant may see of a session: never rate counters or ranked timings.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function publicSession(array $state, array $config, ?Presence $presence = null, ?int $now = null): array
    {
        // Ranked control: what a browser needs to hold its person without knowing anyone's role.
        $ranked = Pipeline::rankedSummary($state, $config);
        $participants = [];
        foreach ($state['participants'] as $p) {
            $live = $presence !== null && $now !== null ? ['online' => $presence->online($p, $now), 'lastSeen' => Presence::lastSeen($p)] : [];
            if ($ranked !== null) {
                $live['rank'] = $ranked['ranks'][(string) $p['ref']] ?? 0;
            }
            $row = ['ref' => (string) $p['ref']];
            if (isset($p['label'])) {
                $row['label'] = $p['label'];
            }
            if (isset($p['actingFor'])) {
                $row['actingFor'] = $p['actingFor'];
            }
            $participants[] = [...$row, 'route' => $p['route'], 'revision' => $p['revision'], 'follows' => $p['follows'], ...$live];
        }
        $views = [];
        if (($config['control']['mode'] ?? null) === 'baton') {
            foreach (SessionState::refs($state) as $ref) {
                $views[$ref] = SessionState::holderOf($state, $ref);
            }
        }

        return [
            'id' => $state['id'],
            'state' => $state['state'],
            // Why it ended ('ended', 'starter-left', 'empty'); null while live.
            'endReason' => $state['state'] === 'ended' ? (string) ($state['endReason'] ?? 'ended') : null,
            'starter' => $state['starter'],
            'revision' => $state['revision'],
            'consent' => $config['consent'],
            'consented' => $state['consented'],
            'routing' => $config['routing'],
            'control' => [
                'mode' => $config['control']['mode'] ?? 'baton',
                ...($ranked !== null ? ['scope' => $ranked['scope'], 'idleMs' => $ranked['idleMs']] : []),
                'views' => $views === [] ? new \stdClass : $views,
            ],
            'participants' => $participants,
        ];
    }

    /**
     * A JSON-safe copy of a config: a host rank function is recorded as `host`.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function snapshot(array $config): array
    {
        if (isset($config['control']['rank']) && $config['control']['rank'] instanceof Closure) {
            $config['control']['rank'] = 'host';
        }
        array_walk_recursive($config, static function (mixed &$value): void {
            if ($value instanceof Closure) {
                $value = 'host';
            }
        });

        return $config;
    }
}
