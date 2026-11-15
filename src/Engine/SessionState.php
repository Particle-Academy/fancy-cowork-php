<?php

declare(strict_types=1);

namespace FancyCowork\Engine;

/**
 * The session state the engine reads and returns: a plain array, the same shape
 * as fancy-cowork-js's `SessionState`. Participants are opaque refs, keyed by
 * ref, and nothing in here is ever a host model.
 *
 * Keys: id, state (requested|live|ended), starter, consented, revision,
 * createdAt, endedAt, participants (ref => participant), control
 * (view => {holder, requests}), last (view => ref => ms), rate
 * (ref => {all, visit, app}).
 */
final class SessionState
{
    /**
     * A fresh session state. Participants given here are already joined.
     *
     * @param  list<array{ref: string, route?: string, label?: string, actingFor?: string}>  $participants
     * @return array<string, mixed>
     */
    public static function create(string $id, string $state, string $starter, array $participants = [], int $now = 0): array
    {
        $joined = [];
        foreach ($participants as $p) {
            $joined[$p['ref']] = self::participant(['from' => $p['ref'], 'now' => $now, ...array_intersect_key($p, array_flip(['route', 'label', 'actingFor']))]);
        }

        return [
            'id' => $id,
            'state' => $state,
            'starter' => $starter,
            'consented' => false,
            'revision' => 0,
            'createdAt' => $now,
            'endedAt' => null,
            'endReason' => null,
            'participants' => $joined,
            'control' => [],
            'last' => [],
            'rate' => [],
        ];
    }

    /**
     * The baton state of a view: its owner holds it until something says otherwise.
     *
     * @param  array<string, mixed>  $state
     * @return array{holder: string, requests: list<string>}
     */
    public static function holderOf(array $state, string $view): array
    {
        return $state['control'][$view] ?? ['holder' => $view, 'requests' => []];
    }

    /**
     * @param  array{from: string, now: int, route?: mixed, label?: mixed, attributes?: mixed, actingFor?: mixed}  $input
     * @return array<string, mixed>
     */
    public static function participant(array $input): array
    {
        $participant = ['ref' => $input['from']];
        foreach (['label', 'attributes', 'actingFor'] as $key) {
            if (isset($input[$key])) {
                $participant[$key] = $input[$key];
            }
        }
        $route = $input['route'] ?? null;
        $participant['route'] = is_string($route) && Wire::isSameOriginPath($route) ? Wire::routeOf($route) : '/';
        $participant['revision'] = 0;
        $participant['follows'] = null;
        $participant['joinedAt'] = $input['now'];

        return $participant;
    }

    /**
     * Whether `$actingFor` is the identity a participant joined with. A ref is the
     * person at the keyboard and `actingFor` the account they act in; the same ref
     * with a different `actingFor` (null counts as a value) is a different person,
     * never a second tab of this one.
     *
     * @param  array<string, mixed>  $participant
     */
    public static function sameIdentity(array $participant, mixed $actingFor): bool
    {
        return ($participant['actingFor'] ?? null) === ($actingFor ?? null);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    public static function refs(array $state): array
    {
        return array_values(array_map(static fn (array $p): string => (string) $p['ref'], $state['participants']));
    }
}
