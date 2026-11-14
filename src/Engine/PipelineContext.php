<?php

declare(strict_types=1);

namespace FancyCowork\Engine;

/**
 * What every middleware sees: the session as it stands, the server-verified
 * sender, the envelope, the target view and the config. `events` collects audit
 * events that are emitted if, and only if, the whole pipeline allows.
 */
final class PipelineContext
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public readonly array $session,
        public readonly string $from,
        public readonly ?string $actingFor,
        public readonly array $envelope,
        public readonly ?string $view,
        public readonly array $config,
        public readonly int $now,
        public readonly EngineHost $host,
    ) {}
}
