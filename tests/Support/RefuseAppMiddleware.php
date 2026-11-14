<?php

declare(strict_types=1);

namespace FancyCowork\Tests\Support;

use Closure;
use FancyCowork\Contracts\IntentMiddleware;
use FancyCowork\Engine\PipelineContext;

/** A host middleware the way a host writes one: a class, resolved from the container. */
final class RefuseAppMiddleware implements IntentMiddleware
{
    public function handle(PipelineContext $ctx, array $intent, Closure $next): array
    {
        return $intent['kind'] === 'app' ? ['allow' => false, 'reason' => 'host.app-closed', 'status' => 403] : $next();
    }
}
