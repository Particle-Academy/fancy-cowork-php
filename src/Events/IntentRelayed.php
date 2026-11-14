<?php

declare(strict_types=1);

namespace FancyCowork\Events;

/** An intent passed the whole pipeline. Its kind and target, never its value or payload. */
final class IntentRelayed extends CoworkEventBase
{
    /** @param  list<string>|null  $to */
    public function __construct(
        string $session,
        string $ref,
        ?string $actingFor,
        int $at,
        public readonly string $kind,
        public readonly ?string $id = null,
        public readonly ?string $view = null,
        public readonly ?array $to = null,
    ) {
        parent::__construct($session, $ref, $actingFor, $at);
    }

    protected function extra(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'view' => $this->view, 'to' => $this->to];
    }
}
