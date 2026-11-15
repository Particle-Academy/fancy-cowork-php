<?php

declare(strict_types=1);

namespace FancyCowork\Engine;

use Closure;
use FancyCowork\Contracts\IntentMiddleware;
use InvalidArgumentException;

/**
 * The intent middleware: one contract, `(ctx, intent, next) => decision`, and
 * the ten shipped defaults in their pinned order. Each default is removable by
 * leaving its name out of the stack; host middleware goes anywhere in it.
 */
final class Pipeline
{
    public const array DEFAULT_MIDDLEWARE = [
        'membership',
        'authorize',
        'payloadSize',
        'sameOrigin',
        'masking',
        'limits',
        'revision',
        'control',
        'rateLimit',
        'audit',
    ];

    /**
     * Resolve a stack: default names become their middleware; an unknown name is a configuration error.
     *
     * @param  list<mixed>|null  $entries
     * @return list<Closure(PipelineContext, array<string, mixed>, Closure): mixed>
     */
    public static function resolve(?array $entries = null): array
    {
        $entries ??= self::DEFAULT_MIDDLEWARE;

        return array_map(static function (mixed $entry): Closure {
            if ($entry instanceof Closure) {
                return $entry;
            }
            if ($entry instanceof IntentMiddleware) {
                return $entry->handle(...);
            }
            if (is_string($entry) && in_array($entry, self::DEFAULT_MIDDLEWARE, true)) {
                return self::{$entry}(...);
            }
            throw new InvalidArgumentException('Unknown cowork middleware '.(is_string($entry) ? '"'.$entry.'"' : get_debug_type($entry)).'.');
        }, array_values($entries));
    }

    /**
     * @param  list<Closure>  $stack
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    public static function run(array $stack, PipelineContext $ctx, array $intent): array
    {
        $dispatch = static function (int $i) use (&$dispatch, $stack, $ctx, $intent): array {
            if ($i >= count($stack)) {
                return Reasons::allow();
            }
            $decision = $stack[$i]($ctx, $intent, static fn (): array => $dispatch($i + 1));

            return is_array($decision) && ($decision['allow'] ?? null) === true ? Reasons::allow() : self::normaliseRefusal($decision);
        };

        return $dispatch(0);
    }

    /** A middleware that returns anything but a well-formed allow refuses. */
    private static function normaliseRefusal(mixed $decision): array
    {
        if (is_array($decision) && ($decision['allow'] ?? null) === false && is_string($decision['reason'] ?? null) && is_int($decision['status'] ?? null)) {
            return $decision;
        }

        return Reasons::refuse(Reasons::MIDDLEWARE, 403);
    }

    private static function askMembers(EngineHost $host, string $ref): bool
    {
        return ($host->members)($ref) === true;
    }

    /**
     * Only `true` or `['allow' => true]` allows; anything else refuses.
     *
     * @param  array<string, mixed>|null  $intent
     * @return array<string, mixed>
     */
    public static function askAuthorize(EngineHost $host, string $ref, string $ability, ?array $intent = null): array
    {
        $answer = ($host->authorize)($ref, $ability, $intent);
        if ($answer === true || (is_array($answer) && ($answer['allow'] ?? null) === true)) {
            return Reasons::allow();
        }
        $reason = is_array($answer) && is_string($answer['reason'] ?? null) && $answer['reason'] !== '' ? $answer['reason'] : Reasons::AUTHORIZE;

        return Reasons::refuse($reason, 403);
    }

    private static function membership(PipelineContext $ctx, array $intent, Closure $next): array
    {
        $host = $ctx->host;
        if ($host->members === null) {
            return Reasons::refuse(Reasons::UNCONFIGURED_MEMBERS, 403);
        }
        $refs = [$ctx->from];
        if ($ctx->view !== null) {
            $refs[] = $ctx->view;
        }
        foreach ($ctx->envelope['to'] ?? [] as $ref) {
            $refs[] = $ref;
        }
        if ($intent['kind'] === 'control' && isset($intent['target'])) {
            $refs[] = $intent['target'];
        }
        foreach (array_unique($refs) as $ref) {
            if (! isset($ctx->session['participants'][$ref]) || ! self::askMembers($host, (string) $ref)) {
                return Reasons::refuse(Reasons::MEMBERSHIP, 403);
            }
        }
        if (! SessionState::sameIdentity($ctx->session['participants'][$ctx->from], $ctx->actingFor)) {
            return Reasons::refuse(Reasons::REF_IN_USE, 409);
        }

        return $next();
    }

    private static function authorize(PipelineContext $ctx, array $intent, Closure $next): array
    {
        if ($ctx->host->authorize === null) {
            return Reasons::refuse(Reasons::UNCONFIGURED_AUTHORIZE, 403);
        }
        $abilities = ['intent:'.$intent['kind']];
        if ($ctx->view !== null) {
            $abilities[] = 'view:'.$ctx->view;
            if ($ctx->view !== $ctx->from) {
                $abilities[] = 'drive';
            }
        }
        foreach ($abilities as $ability) {
            $decision = self::askAuthorize($ctx->host, $ctx->from, $ability, $intent);
            if ($decision['allow'] !== true) {
                return $decision;
            }
        }

        return $next();
    }

    private static function payloadSize(PipelineContext $ctx, array $intent, Closure $next): array
    {
        $size = $ctx->config['size'];
        if ($intent['kind'] === 'app') {
            if (Wire::jsonBytes($intent['payload']) > $size['appBytes']) {
                return Reasons::refuse(Reasons::SIZE, 413);
            }
        } elseif (Wire::jsonBytes($intent) > $size['intentBytes']) {
            return Reasons::refuse(Reasons::SIZE, 413);
        }

        return $next();
    }

    private static function sameOrigin(PipelineContext $ctx, array $intent, Closure $next): array
    {
        if ($intent['kind'] === 'visit' && ! Wire::isSameOriginPath($intent['url'])) {
            return Reasons::refuse(Reasons::ORIGIN, 422);
        }

        return $next();
    }

    private static function masking(PipelineContext $ctx, array $intent, Closure $next): array
    {
        $limits = $ctx->config['limits'];
        if ($intent['kind'] === 'field' && Glob::any($limits['mask'], $intent['handle'])) {
            return Reasons::refuse(Reasons::MASKED, 403);
        }
        if (in_array($intent['kind'], ['field', 'click', 'submit'], true) && Glob::any($limits['viewOnly'], $intent['handle'])) {
            return Reasons::refuse(Reasons::VIEW_ONLY, 403);
        }

        return $next();
    }

    /**
     * One limits rule against one intent. `null` = not applicable (a criterion it
     * names has no value on this intent), else whether it matches. An undefined
     * predicate fails closed: it matches a deny rule and misses an allow rule.
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $intent
     */
    private static function ruleMatches(array $rule, PipelineContext $ctx, array $intent, bool $isDeny): ?bool
    {
        $handle = Wire::handleOf($intent);
        if (array_key_exists('handles', $rule) && $handle === null) {
            return null;
        }
        if (array_key_exists('kinds', $rule) && ! in_array($intent['kind'], (array) $rule['kinds'], true)) {
            return false;
        }
        if (array_key_exists('handles', $rule) && ! Glob::any((array) $rule['handles'], $handle)) {
            return false;
        }
        if (array_key_exists('routes', $rule)) {
            $route = $intent['kind'] === 'visit'
                ? Wire::routeOf($intent['url'])
                : (string) ($ctx->session['participants'][$ctx->view]['route'] ?? '');
            if (! Glob::any((array) $rule['routes'], $route)) {
                return false;
            }
        }
        if (array_key_exists('when', $rule)) {
            $answer = $ctx->host->predicate !== null && is_string($rule['when'])
                ? ($ctx->host->predicate)($rule['when'], $ctx, $intent)
                : null;
            if ($answer === null) {
                return $isDeny;
            }
            if ($answer !== true) {
                return false;
            }
        }

        return true;
    }

    private static function limits(PipelineContext $ctx, array $intent, Closure $next): array
    {
        if (! Wire::isViewKind($intent['kind'])) {
            return $next();
        }
        foreach ($ctx->config['limits']['deny'] as $rule) {
            if (self::ruleMatches((array) $rule, $ctx, $intent, true) === true) {
                return Reasons::refuse(Reasons::LIMIT, 403);
            }
        }
        $applicable = false;
        foreach ($ctx->config['limits']['allow'] as $rule) {
            $result = self::ruleMatches((array) $rule, $ctx, $intent, false);
            if ($result === null) {
                continue;
            }
            $applicable = true;
            if ($result) {
                return $next();
            }
        }

        return $applicable ? Reasons::refuse(Reasons::LIMIT, 403) : $next();
    }

    private static function revision(PipelineContext $ctx, array $intent, Closure $next): array
    {
        if (Wire::isViewKind($intent['kind'])) {
            $view = $ctx->session['participants'][$ctx->view] ?? null;
            if ($view !== null && ($ctx->envelope['revision'] ?? -1) < $view['revision']) {
                return Reasons::refuse(Reasons::STALE, 409);
            }
        }

        return $next();
    }

    /** @param  array<string, mixed>  $config */
    public static function rankOf(array $config, string $ref): float
    {
        if (($config['control']['mode'] ?? null) !== 'ranked') {
            return 0;
        }
        $rank = $config['control']['rank'] ?? null;
        $value = $rank instanceof Closure ? $rank($ref) : (is_array($rank) ? ($rank[$ref] ?? null) : null);

        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : 0;
    }

    private static function control(PipelineContext $ctx, array $intent, Closure $next): array
    {
        $mode = $ctx->config['control']['mode'] ?? 'baton';
        if ($intent['kind'] === 'control') {
            if ($mode !== 'baton') {
                return Reasons::refuse(Reasons::CONTROL_MODE, 409);
            }
            $state = SessionState::holderOf($ctx->session, $ctx->view);
            if (in_array($intent['op'], ['grant', 'release'], true) && $ctx->from !== $state['holder']) {
                return Reasons::refuse(Reasons::CONTROL, 409);
            }
            if ($intent['op'] === 'revoke' && $ctx->from !== $ctx->view) {
                return Reasons::refuse(Reasons::CONTROL, 409);
            }

            return $next();
        }
        if (! Wire::isViewKind($intent['kind'])) {
            return $next();
        }
        if ($mode === 'baton' && SessionState::holderOf($ctx->session, $ctx->view)['holder'] !== $ctx->from) {
            return Reasons::refuse(Reasons::CONTROL, 409);
        }
        if ($mode === 'ranked') {
            $idleMs = (int) ($ctx->config['control']['idleMs'] ?? 3000);
            $mine = self::rankOf($ctx->config, $ctx->from);
            $blockedUntil = null;
            // scope 'session' (mirrored co-work): a higher rank's commit on ANY view
            // blocks; anything else is per view, the commits into this view only.
            $views = ($ctx->config['control']['scope'] ?? 'view') === 'session'
                ? ($ctx->session['last'] ?? [])
                : [$ctx->view => $ctx->session['last'][$ctx->view] ?? []];
            foreach ($views as $times) {
                foreach ($times as $ref => $at) {
                    $ref = (string) $ref;
                    if ($ref === $ctx->from || self::rankOf($ctx->config, $ref) <= $mine || $ctx->now - $at >= $idleMs) {
                        continue;
                    }
                    $blockedUntil = max($blockedUntil ?? 0, $at + $idleMs);
                }
            }
            if ($blockedUntil !== null) {
                return Reasons::refuse(Reasons::CONTROL, 409, $blockedUntil);
            }
        }

        return $next();
    }

    /** @param  list<int>  $times */
    private static function within(array $times, int $now, int $window): int
    {
        return count(array_filter($times, static fn (int $t): bool => $t > $now - $window));
    }

    private static function rateLimit(PipelineContext $ctx, array $intent, Closure $next): array
    {
        $rate = $ctx->config['rate'];
        $mine = $ctx->session['rate'][$ctx->from] ?? ['all' => [], 'visit' => [], 'app' => [], 'cursor' => []];
        // A cursor spends only its own bucket, so cursors never starve the intents.
        if ($intent['kind'] === 'cursor') {
            if (self::within($mine['cursor'] ?? [], $ctx->now, 1000) >= $rate['cursorsPerSecond']) {
                return Reasons::refuse(Reasons::RATE, 429);
            }

            return $next();
        }
        if (self::within($mine['all'], $ctx->now, 1000) >= $rate['intentsPerSecond']) {
            return Reasons::refuse(Reasons::RATE, 429);
        }
        if ($intent['kind'] === 'visit' && self::within($mine['visit'], $ctx->now, 1000) >= $rate['visitsPerSecond']) {
            return Reasons::refuse(Reasons::RATE, 429);
        }
        if ($intent['kind'] === 'app' && self::within($mine['app'], $ctx->now, 60000) >= $rate['appPerMinute']) {
            return Reasons::refuse(Reasons::RATE, 429);
        }

        return $next();
    }

    /** Never refuses. Records `IntentRelayed` only when everything after it allowed too. */
    private static function audit(PipelineContext $ctx, array $intent, Closure $next): array
    {
        $decision = $next();
        if (($decision['allow'] ?? false) === true) {
            $event = ['type' => 'IntentRelayed', 'session' => $ctx->session['id'], 'ref' => $ctx->from];
            if ($ctx->actingFor !== null) {
                $event['actingFor'] = $ctx->actingFor;
            }
            if (isset($ctx->envelope['id'])) {
                $event['id'] = $ctx->envelope['id'];
            }
            $event['kind'] = $intent['kind'];
            if ($ctx->view !== null) {
                $event['view'] = $ctx->view;
            }
            if (isset($ctx->envelope['to'])) {
                $event['to'] = array_values($ctx->envelope['to']);
            }
            $event['at'] = $ctx->now;
            $ctx->events[] = $event;
        }

        return $decision;
    }
}
