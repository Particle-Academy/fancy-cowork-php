<?php

declare(strict_types=1);

namespace FancyCowork\Events;

/** A view's baton moved: `op` is grant, release, revoke, or `leave` when its holder left. */
final class ControlChanged extends CoworkEventBase
{
    public function __construct(
        string $session,
        string $ref,
        ?string $actingFor,
        int $at,
        public readonly string $view,
        public readonly string $holder,
        public readonly string $op,
    ) {
        parent::__construct($session, $ref, $actingFor, $at);
    }

    protected function extra(): array
    {
        return ['view' => $this->view, 'holder' => $this->holder, 'op' => $this->op];
    }
}
