<?php

declare(strict_types=1);

namespace FancyCowork\Events;

/** A participant left; any view it held went back to its owner. */
final class PeerLeft extends CoworkEventBase
{
    /** Why, when it was not an explicit request: `expired`, `starter-left` or `empty`. */
    public function __construct(string $session, string $ref, ?string $actingFor, int $at, public readonly ?string $reason = null)
    {
        parent::__construct($session, $ref, $actingFor, $at);
    }

    protected function extra(): array
    {
        return ['reason' => $this->reason];
    }
}
