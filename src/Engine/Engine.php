<?php

declare(strict_types=1);

namespace FancyCowork\Engine;

/**
 * The co-work pipeline, pure: no I/O, no clock, no storage. Every function
 * takes a session state and returns a {@see Result}. PHP arrays are values, so
 * the state handed back on a refusal is the state passed in, untouched.
 *
 * Contract v1, pinned in `conformance/cowork-v1.json`, shared byte for byte
 * with fancy-cowork-js. Where this file and the fixtures disagree, the fixtures
 * are right.
 */
final class Engine
{
    private static function prelude(EngineHost $host, mixed $from): ?array
    {
        if (! $host->identity) {
            return Reasons::refuse(Reasons::UNCONFIGURED_IDENTITY, 403);
        }
        if ($host->config === null) {
            return Reasons::refuse(Reasons::UNCONFIGURED_CONFIG, 403);
        }
        if (! Wire::isRef($from)) {
            return Reasons::refuse(Reasons::UNIDENTIFIED, 401);
        }

        return null;
    }

    /** @param  array<string, mixed>  $state */
    private static function sessionGate(array $state): ?array
    {
        if ($state['state'] === 'ended') {
            return Reasons::refuse(Reasons::ENDED, 410);
        }
        if ($state['state'] !== 'live') {
            return Reasons::refuse(Reasons::NOT_LIVE, 409);
        }

        return null;
    }

    /** @param  array<string, mixed>  $state */
    private static function refused(array $state, array $decision): Result
    {
        return new Result($decision, $state, []);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $config
     */
    private static function subjectOf(array $state, array $config): string
    {
        return isset($config['subject']) && is_string($config['subject']) ? $config['subject'] : (string) $state['starter'];
    }

    private static function membersGate(EngineHost $host, string $ref): ?array
    {
        if ($host->members === null) {
            return Reasons::refuse(Reasons::UNCONFIGURED_MEMBERS, 403);
        }
        if (($host->members)($ref) !== true) {
            return Reasons::refuse(Reasons::MEMBERSHIP, 403);
        }

        return null;
    }

    private static function abilityGate(EngineHost $host, string $ref, string $ability): ?array
    {
        if ($host->authorize === null) {
            return Reasons::refuse(Reasons::UNCONFIGURED_AUTHORIZE, 403);
        }
        $decision = Pipeline::askAuthorize($host, $ref, $ability);

        return $decision['allow'] === true ? null : $decision;
    }

    /** @param  array<string, mixed>  $input */
    private static function actor(array $input): array
    {
        return isset($input['actingFor']) ? ['actingFor' => $input['actingFor']] : [];
    }

    /** @param  array<string, mixed>  $input */
    private static function event(string $type, string $session, array $input): array
    {
        return ['type' => $type, 'session' => $session, 'ref' => $input['from'], ...self::actor($input), 'at' => $input['now']];
    }

    /**
     * Run one intent through validation, the session gate and the middleware stack.
     *
     * @param  array<string, mixed>  $state
     * @param  array{from: string, actingFor?: string|null, envelope: mixed, intent: mixed, now: int}  $input
     */
    public static function evaluateIntent(EngineHost $host, array $state, array $input): Result
    {
        if ($early = self::prelude($host, $input['from'] ?? null)) {
            return self::refused($state, $early);
        }
        $envelope = Wire::isRecord($input['envelope']) ? $input['envelope'] : [];
        if (! Wire::validIntent($input['intent'], $envelope)) {
            return self::refused($state, Reasons::refuse(Reasons::INVALID, 422));
        }
        if ($gate = self::sessionGate($state)) {
            return self::refused($state, $gate);
        }

        $intent = $input['intent'];
        $from = $input['from'];
        $now = $input['now'];
        $view = isset($envelope['view']) ? (string) $envelope['view'] : null;
        $ctx = new PipelineContext($state, $from, $input['actingFor'] ?? null, $envelope, $view, $host->config, $now, $host);
        $decision = Pipeline::run(Pipeline::resolve($host->middleware), $ctx, $intent);
        if ($decision['allow'] !== true) {
            return self::refused($state, $decision);
        }

        $events = $ctx->events;
        $next = $state;
        $rate = $next['rate'][$from] ?? ['all' => [], 'visit' => [], 'app' => [], 'cursor' => []];
        $keep = static fn (array $times, int $window): array => [...array_values(array_filter($times, static fn (int $t): bool => $t > $now - $window)), $now];
        if ($intent['kind'] === 'cursor') {
            $rate['cursor'] = $keep($rate['cursor'] ?? [], 1000);
        } else {
            $rate['all'] = $keep($rate['all'], 1000);
        }
        if ($intent['kind'] === 'visit') {
            $rate['visit'] = $keep($rate['visit'], 1000);
        }
        if ($intent['kind'] === 'app') {
            $rate['app'] = $keep($rate['app'], 60000);
        }
        $next['rate'][$from] = $rate;

        if ($view !== null && Wire::isViewKind($intent['kind'])) {
            $next['last'][$view][$from] = $now;
            if ($intent['kind'] === 'visit' && isset($next['participants'][$view])) {
                $next['participants'][$view]['route'] = Wire::routeOf($intent['url']);
                $next['participants'][$view]['revision']++;
            }
        }
        if ($intent['kind'] === 'control' && $view !== null) {
            $current = SessionState::holderOf($next, $view);
            $changed = null;
            switch ($intent['op']) {
                case 'request':
                    if ($from !== $current['holder'] && ! in_array($from, $current['requests'], true)) {
                        $next['control'][$view] = ['holder' => $current['holder'], 'requests' => [...$current['requests'], $from]];
                    }
                    break;
                case 'grant':
                    $target = (string) $intent['target'];
                    $next['control'][$view] = ['holder' => $target, 'requests' => array_values(array_filter($current['requests'], static fn (string $r): bool => $r !== $target))];
                    $changed = $target;
                    break;
                case 'release':
                    $next['control'][$view] = ['holder' => $view, 'requests' => $current['requests']];
                    $changed = $view;
                    break;
                default:
                    $next['control'][$view] = ['holder' => $view, 'requests' => []];
                    $changed = $view;
            }
            if ($changed !== null) {
                $events[] = ['type' => 'ControlChanged', 'session' => $next['id'], 'ref' => $from, ...self::actor($input), 'view' => $view, 'holder' => $changed, 'op' => $intent['op'], 'at' => $now];
            }
        }
        $next['revision']++;

        return new Result(Reasons::allow(), $next, $events);
    }

    /**
     * Join: identity, config, ended, not live, members, ability, already joined, consent, full.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $input  from, now, and optionally actingFor, label, attributes, route
     */
    public static function evaluateJoin(EngineHost $host, array $state, array $input): Result
    {
        $checks = self::prelude($host, $input['from'] ?? null) ?? self::sessionGate($state) ?? self::membersGate($host, $input['from']) ?? self::abilityGate($host, $input['from'], 'join');
        if ($checks) {
            return self::refused($state, $checks);
        }
        if (isset($state['participants'][$input['from']])) {
            // The same person again (a second tab) changes nothing; a different
            // identity on this ref is refused, never merged into one participant.
            return SessionState::sameIdentity($state['participants'][$input['from']], $input['actingFor'] ?? null)
                ? new Result(Reasons::allow(), $state, [])
                : self::refused($state, Reasons::refuse(Reasons::REF_IN_USE, 409));
        }
        $config = $host->config;
        if ($config['consent'] === 'accept' && ! $state['consented'] && $input['from'] !== self::subjectOf($state, $config)) {
            return self::refused($state, Reasons::refuse(Reasons::CONSENT_PENDING, 403));
        }
        if (count($state['participants']) >= $config['maxParticipants']) {
            return self::refused($state, Reasons::refuse(Reasons::FULL, 409));
        }
        $next = $state;
        $next['participants'][$input['from']] = SessionState::participant($input);
        $next['revision']++;

        return new Result(Reasons::allow(), $next, [self::event('PeerJoined', $next['id'], $input)]);
    }

    /**
     * Activate: identity, config, ended, not requested, members, ability, consent, full.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $input
     */
    public static function evaluateActivate(EngineHost $host, array $state, array $input): Result
    {
        if ($early = self::prelude($host, $input['from'] ?? null)) {
            return self::refused($state, $early);
        }
        if ($state['state'] === 'ended') {
            return self::refused($state, Reasons::refuse(Reasons::ENDED, 410));
        }
        if ($state['state'] !== 'requested') {
            return self::refused($state, Reasons::refuse(Reasons::NOT_REQUESTED, 409));
        }
        if ($checks = self::membersGate($host, $input['from']) ?? self::abilityGate($host, $input['from'], 'activate')) {
            return self::refused($state, $checks);
        }
        $config = $host->config;
        if ($config['consent'] === 'accept' && ! $state['consented'] && $input['from'] !== self::subjectOf($state, $config)) {
            return self::refused($state, Reasons::refuse(Reasons::CONSENT_PENDING, 403));
        }
        $joining = ! isset($state['participants'][$input['from']]);
        if ($joining && count($state['participants']) >= $config['maxParticipants']) {
            return self::refused($state, Reasons::refuse(Reasons::FULL, 409));
        }
        $next = $state;
        $next['state'] = 'live';
        if ($joining) {
            $next['participants'][$input['from']] = SessionState::participant($input);
        }
        $next['revision']++;
        $events = [self::event('SessionActivated', $next['id'], $input)];
        if ($joining) {
            $events[] = self::event('PeerJoined', $next['id'], $input);
        }

        return new Result(Reasons::allow(), $next, $events);
    }

    /**
     * Consent: identity, config, ended, the sender is the subject.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $input
     */
    public static function evaluateConsent(EngineHost $host, array $state, array $input): Result
    {
        if ($early = self::prelude($host, $input['from'] ?? null)) {
            return self::refused($state, $early);
        }
        if ($state['state'] === 'ended') {
            return self::refused($state, Reasons::refuse(Reasons::ENDED, 410));
        }
        if ($input['from'] !== self::subjectOf($state, $host->config)) {
            return self::refused($state, Reasons::refuse(Reasons::NOT_SUBJECT, 403));
        }
        $next = $state;
        $next['consented'] = true;
        $next['revision']++;

        return new Result(Reasons::allow(), $next, [self::event('ConsentGiven', $next['id'], $input)]);
    }

    /**
     * End: identity, config, ended, live member, ability `end`.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $input
     */
    public static function evaluateEnd(EngineHost $host, array $state, array $input): Result
    {
        if ($early = self::prelude($host, $input['from'] ?? null)) {
            return self::refused($state, $early);
        }
        if ($state['state'] === 'ended') {
            return self::refused($state, Reasons::refuse(Reasons::ENDED, 410));
        }
        if ($host->members === null) {
            return self::refused($state, Reasons::refuse(Reasons::UNCONFIGURED_MEMBERS, 403));
        }
        if (! isset($state['participants'][$input['from']]) || ($host->members)($input['from']) !== true) {
            return self::refused($state, Reasons::refuse(Reasons::MEMBERSHIP, 403));
        }
        if ($ability = self::abilityGate($host, $input['from'], 'end')) {
            return self::refused($state, $ability);
        }
        $next = $state;
        $next['state'] = 'ended';
        $next['endedAt'] = $input['now'];
        $next['revision']++;

        return new Result(Reasons::allow(), $next, [self::event('SessionEnded', $next['id'], $input)]);
    }

    /**
     * Leave: the sender must be a participant. Nothing else is asked.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $input
     */
    public static function evaluateLeave(EngineHost $host, array $state, array $input): Result
    {
        $ref = $input['from'];
        if (! Wire::isRef($ref)) {
            return self::refused($state, Reasons::refuse(Reasons::UNIDENTIFIED, 401));
        }
        if (! isset($state['participants'][$ref])) {
            return self::refused($state, Reasons::refuse(Reasons::MEMBERSHIP, 403));
        }
        $next = $state;
        $events = [self::event('PeerLeft', $next['id'], $input)];
        unset($next['participants'][$ref], $next['control'][$ref], $next['last'][$ref]);
        foreach ($next['control'] as $view => $control) {
            $view = (string) $view;
            $requests = array_values(array_filter($control['requests'], static fn (string $r): bool => $r !== $ref));
            if ($control['holder'] === $ref) {
                $next['control'][$view] = ['holder' => $view, 'requests' => $requests];
                $events[] = ['type' => 'ControlChanged', 'session' => $next['id'], 'ref' => $ref, 'view' => $view, 'holder' => $view, 'op' => 'leave', 'at' => $input['now']];
            } else {
                $next['control'][$view] = ['holder' => $control['holder'], 'requests' => $requests];
            }
        }
        foreach ($next['participants'] as $key => $participant) {
            if ($participant['follows'] === $ref) {
                $next['participants'][$key]['follows'] = null;
            }
        }
        foreach ($next['last'] as $view => $times) {
            unset($next['last'][$view][$ref]);
        }
        $next['revision']++;

        return new Result(Reasons::allow(), $next, $events);
    }

    /**
     * Follow (or `target: null` to stop): identity, config, ended, not live, members, ability `follow`.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $input  plus `target` (?string)
     */
    public static function evaluateFollow(EngineHost $host, array $state, array $input): Result
    {
        if ($early = self::prelude($host, $input['from'] ?? null) ?? self::sessionGate($state)) {
            return self::refused($state, $early);
        }
        if ($host->members === null) {
            return self::refused($state, Reasons::refuse(Reasons::UNCONFIGURED_MEMBERS, 403));
        }
        $target = $input['target'] ?? null;
        if ($target !== null && ! Wire::isRef($target)) {
            return self::refused($state, Reasons::refuse(Reasons::INVALID, 422));
        }
        foreach ($target === null ? [$input['from']] : [$input['from'], $target] as $ref) {
            if (! isset($state['participants'][$ref]) || ($host->members)($ref) !== true) {
                return self::refused($state, Reasons::refuse(Reasons::MEMBERSHIP, 403));
            }
        }
        if ($target !== null && ($ability = self::abilityGate($host, $input['from'], 'follow'))) {
            return self::refused($state, $ability);
        }
        $next = $state;
        $next['participants'][$input['from']]['follows'] = $target === $input['from'] ? null : $target;
        $next['revision']++;

        return new Result(Reasons::allow(), $next, []);
    }
}
