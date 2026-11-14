<?php

declare(strict_types=1);

namespace FancyCowork\Audit;

use FancyCowork\Contracts\CoworkEvent;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * OPTIONAL. Writes every cowork event to `cowork_audit`. Registered only when
 * `fancy-cowork.audit.table` is true; audit is events first, and this is one
 * place a host may choose to keep them.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly ConnectionResolverInterface $resolver,
        private readonly ?string $connection = null,
    ) {}

    public function handle(CoworkEvent $event): void
    {
        $data = $event->toArray();
        unset($data['type'], $data['session'], $data['ref'], $data['actingFor'], $data['at']);
        $this->resolver->connection($this->connection)->table('cowork_audit')->insert([
            'session_id' => $event->session,
            'type' => $event->type(),
            'ref' => $event->ref,
            'acting_for' => $event->actingFor,
            'data' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
            'at' => $event->at,
        ]);
    }
}
