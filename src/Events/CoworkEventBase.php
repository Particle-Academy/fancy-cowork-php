<?php

declare(strict_types=1);

namespace FancyCowork\Events;

use FancyCowork\Contracts\CoworkEvent;
use ReflectionClass;

/** The fields every audit event carries: the session, both refs, and when (ms). */
abstract class CoworkEventBase implements CoworkEvent
{
    public function __construct(
        public readonly string $session,
        public readonly string $ref,
        public readonly ?string $actingFor,
        public readonly int $at,
    ) {}

    public function type(): string
    {
        return (new ReflectionClass($this))->getShortName();
    }

    /**
     * Build the event class the engine named.
     *
     * @param  array<string, mixed>  $event
     */
    public static function fromEngine(array $event): CoworkEvent
    {
        $session = (string) $event['session'];
        $ref = (string) $event['ref'];
        $actingFor = isset($event['actingFor']) ? (string) $event['actingFor'] : null;
        $at = (int) $event['at'];

        return match ($event['type']) {
            'SessionRequested' => new SessionRequested($session, $ref, $actingFor, $at),
            'SessionActivated' => new SessionActivated($session, $ref, $actingFor, $at),
            'PeerJoined' => new PeerJoined($session, $ref, $actingFor, $at),
            'PeerLeft' => new PeerLeft($session, $ref, $actingFor, $at, isset($event['reason']) ? (string) $event['reason'] : null),
            'ConsentGiven' => new ConsentGiven($session, $ref, $actingFor, $at),
            'SessionEnded' => new SessionEnded($session, $ref, $actingFor, $at, isset($event['reason']) ? (string) $event['reason'] : null),
            'ControlChanged' => new ControlChanged($session, $ref, $actingFor, $at, (string) $event['view'], (string) $event['holder'], (string) $event['op']),
            'IntentRelayed' => new IntentRelayed($session, $ref, $actingFor, $at, (string) $event['kind'], $event['id'] ?? null, $event['view'] ?? null, $event['to'] ?? null),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type(),
            'session' => $this->session,
            'ref' => $this->ref,
            'actingFor' => $this->actingFor,
            ...$this->extra(),
            'at' => $this->at,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string, mixed> */
    protected function extra(): array
    {
        return [];
    }
}
