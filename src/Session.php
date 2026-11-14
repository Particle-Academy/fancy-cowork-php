<?php

declare(strict_types=1);

namespace FancyCowork;

/**
 * A read-only view of a session, as the host's resolvers see it. Never a model
 * the host can save; the refs in it are the host's own strings.
 */
final readonly class Session
{
    /** @param  list<Participant>  $participants  live participants, in join order */
    public function __construct(
        public string $id,
        public string $state,
        public string $starter,
        public bool $consented,
        public int $revision,
        public array $participants,
    ) {}

    /** @param  array<string, mixed>  $state  an engine session state */
    public static function fromState(array $state): self
    {
        $participants = [];
        foreach ($state['participants'] as $p) {
            $participants[] = new Participant((string) $p['ref'], $p['label'] ?? null, $p['attributes'] ?? null, $p['actingFor'] ?? null);
        }

        return new self((string) $state['id'], (string) $state['state'], (string) $state['starter'], (bool) $state['consented'], (int) $state['revision'], $participants);
    }

    public function has(string $ref): bool
    {
        foreach ($this->participants as $participant) {
            if ($participant->ref === $ref) {
                return true;
            }
        }

        return false;
    }
}
