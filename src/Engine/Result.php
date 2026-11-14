<?php

declare(strict_types=1);

namespace FancyCowork\Engine;

/**
 * A decision and the state to keep. On a refusal `state` is exactly the state
 * passed in and `events` is empty, so a refusal never leaves a half-applied
 * change behind.
 */
final readonly class Result
{
    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        public array $decision,
        public array $state,
        public array $events = [],
    ) {}

    public function allowed(): bool
    {
        return ($this->decision['allow'] ?? false) === true;
    }
}
