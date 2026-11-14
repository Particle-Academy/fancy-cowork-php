<?php

declare(strict_types=1);

namespace FancyCowork\Contracts;

use Closure;
use FancyCowork\Engine\PipelineContext;

/**
 * A host middleware, inserted anywhere in the intent pipeline. Return
 * `$next()` to pass, or a refusal (`['allow' => false, 'reason' => ..., 'status' => ...]`).
 * Anything that is not a well-formed allow or refusal refuses with
 * `cowork.denied.middleware`. A plain closure with the same signature works too.
 */
interface IntentMiddleware
{
    /**
     * @param  array<string, mixed>  $intent
     * @param  Closure(): array<string, mixed>  $next
     * @return array<string, mixed>
     */
    public function handle(PipelineContext $ctx, array $intent, Closure $next): array;
}
