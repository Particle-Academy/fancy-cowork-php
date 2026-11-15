<?php

declare(strict_types=1);

namespace FancyCowork\Events;

/** The session ended; everything on it is refused from now on. */
final class SessionEnded extends CoworkEventBase
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
