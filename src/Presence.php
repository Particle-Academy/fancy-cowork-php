<?php

declare(strict_types=1);

namespace FancyCowork;

use FancyCowork\Engine\Engine;
use FancyCowork\Engine\EngineHost;
use FancyCowork\Engine\SessionState;

/**
 * Live presence and expiry, pure: no I/O and no clock of its own. A closed
 * browser never says goodbye, so the server cannot wait for one:
 *
 * - every request from a live participant (same ref, same `actingFor`) refreshes
 *   its `lastSeen`, and so does `POST {prefix}/sessions/{id}/heartbeat`;
 * - a participant not seen for `presence.idle_ms` is removed (`PeerLeft`, reason
 *   `expired`), exactly as if it had left: any baton it held goes back;
 * - when the starter is gone the session ends (`SessionEnded`, reason
 *   `starter-left`) if `presence.end_when_starter_leaves`;
 * - a session with no participant for `presence.empty_ms` ends (reason `empty`).
 *
 * Applied lazily on every read and write of a session, inside its row lock, and
 * by `cowork:prune` for sessions nobody touches.
 */
final class Presence
{
    /**
     * @param  array{idle_ms: int, empty_ms: int, end_when_starter_leaves: bool}  $settings
     */
    public function __construct(private readonly array $settings) {}

    /** @param  array<string, mixed>  $settings  the `fancy-cowork.presence` config */
    public static function fromConfig(array $settings): self
    {
        return new self([
            'idle_ms' => max(1, (int) ($settings['idle_ms'] ?? 120_000)),
            'empty_ms' => max(0, (int) ($settings['empty_ms'] ?? 600_000)),
            'end_when_starter_leaves' => ($settings['end_when_starter_leaves'] ?? true) !== false,
        ]);
    }

    public function idleMs(): int
    {
        return $this->settings['idle_ms'];
    }

    /** When a participant was last seen: its heartbeat, else when it joined. */
    public static function lastSeen(array $participant): int
    {
        return (int) ($participant['lastSeen'] ?? $participant['joinedAt'] ?? 0);
    }

    /** @param  array<string, mixed>  $participant */
    public function online(array $participant, int $now): bool
    {
        return $now - self::lastSeen($participant) < $this->settings['idle_ms'];
    }

    /**
     * Refresh the caller's `lastSeen` when it is a live participant with the same
     * identity it joined with. A different identity on that ref proves nothing.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function touch(array $state, string $ref, ?string $actingFor, int $now): array
    {
        $participant = $state['participants'][$ref] ?? null;
        if ($participant === null || ! SessionState::sameIdentity($participant, $actingFor)) {
            return $state;
        }
        $state['participants'][$ref]['lastSeen'] = $now;

        return $state;
    }

    /**
     * Remove idle participants, then end the session if its starter is gone or it
     * has been empty for `empty_ms`. `$emptySince` is when its last participant
     * left, as the store recorded it (null: never had one leave).
     *
     * @param  array<string, mixed>  $state
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>, expired: int, ended: bool}
     */
    public function expire(array $state, int $now, ?int $emptySince = null): array
    {
        if ($state['state'] === 'ended') {
            return ['state' => $state, 'events' => [], 'expired' => 0, 'ended' => false];
        }
        $events = [];
        $expired = 0;
        $leaver = new EngineHost(identity: true, config: null, members: null, authorize: null);
        foreach ($state['participants'] as $ref => $participant) {
            if ($this->online($participant, $now)) {
                continue;
            }
            $actor = isset($participant['actingFor']) ? ['actingFor' => $participant['actingFor']] : [];
            $result = Engine::evaluateLeave($leaver, $state, ['from' => (string) $ref, ...$actor, 'now' => $now]);
            $state = $result->state;
            foreach ($result->events as $event) {
                $events[] = $event['type'] === 'PeerLeft' ? [...$event, 'reason' => 'expired'] : $event;
            }
            $expired++;
            $emptySince = $now;
        }

        $ended = $this->endIfAbandoned($state, $now, $emptySince);

        return ['state' => $ended['state'], 'events' => [...$events, ...$ended['events']], 'expired' => $expired, 'ended' => $ended['ended']];
    }

    /**
     * End a session whose starter is gone (when the host says so) or that has been
     * empty for `empty_ms`.
     *
     * @param  array<string, mixed>  $state
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>, ended: bool}
     */
    public function endIfAbandoned(array $state, int $now, ?int $emptySince = null): array
    {
        if ($state['state'] === 'ended') {
            return ['state' => $state, 'events' => [], 'ended' => false];
        }
        $reason = null;
        if ($this->settings['end_when_starter_leaves'] && ! isset($state['participants'][$state['starter']])) {
            $reason = 'starter-left';
        } elseif ($state['participants'] === [] && $now - ($emptySince ?? (int) $state['createdAt']) >= $this->settings['empty_ms']) {
            $reason = 'empty';
        }
        if ($reason === null) {
            return ['state' => $state, 'events' => [], 'ended' => false];
        }
        $state['state'] = 'ended';
        $state['endedAt'] = $now;
        $state['revision']++;

        return [
            'state' => $state,
            'events' => [['type' => 'SessionEnded', 'session' => $state['id'], 'ref' => (string) $state['starter'], 'reason' => $reason, 'at' => $now]],
            'ended' => true,
        ];
    }
}
