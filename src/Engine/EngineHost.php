<?php

declare(strict_types=1);

namespace FancyCowork\Engine;

use Closure;

/**
 * What the engine asks the host. `null` means the contract is unbound, and an
 * unbound contract denies. There is no allow-by-default anywhere in here.
 */
final readonly class EngineHost
{
    /**
     * @param  array<string, mixed>|null  $config  the resolved session config
     * @param  (Closure(string): mixed)|null  $members  only a literal `true` admits
     * @param  (Closure(string, string, ?array<string, mixed>): mixed)|null  $authorize  only `true` or `['allow' => true]` allows
     * @param  (Closure(string, PipelineContext, array<string, mixed>): ?bool)|null  $predicate  `null` = not defined (fails closed)
     * @param  list<mixed>|null  $middleware  default names, closures or IntentMiddleware instances; null = the defaults
     */
    public function __construct(
        public bool $identity,
        public ?array $config,
        public ?Closure $members,
        public ?Closure $authorize,
        public ?Closure $predicate = null,
        public ?array $middleware = null,
    ) {}
}
