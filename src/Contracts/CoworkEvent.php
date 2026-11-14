<?php

declare(strict_types=1);

namespace FancyCowork\Contracts;

/**
 * Every audit event. Listen on this interface to receive all of them; the host
 * decides whether and where to keep them. No event carries an intent's value or
 * an app payload.
 */
interface CoworkEvent
{
    public string $session { get; }

    public string $ref { get; }

    public ?string $actingFor { get; }

    public int $at { get; }

    /** The event's short name, as fancy-cowork-js names it (`PeerJoined`, ...). */
    public function type(): string;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
