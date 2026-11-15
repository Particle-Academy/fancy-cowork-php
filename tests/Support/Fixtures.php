<?php

declare(strict_types=1);

namespace FancyCowork\Tests\Support;

use FancyCowork\Engine\Config;
use FancyCowork\Engine\EngineHost;
use FancyCowork\Engine\Glob;
use FancyCowork\Engine\PipelineContext;
use FancyCowork\Engine\SessionState;

/**
 * The shared fixture file and the harness that turns one case into an engine
 * host. Byte-identical with fancy-cowork-js and agent-integrations; the hash is
 * pinned in every leg, so "the same fixtures" is a test result, not a promise.
 */
final class Fixtures
{
    public const string SHA256 = 'bcf40fb69bf9d1ce03bdc2dd426bc42f1dd890dc7a6716314f3bdccb6b7c7106';

    public static function path(): string
    {
        return dirname(__DIR__, 2).'/conformance/cowork-v1.json';
    }

    public static function raw(): string
    {
        return (string) file_get_contents(self::path());
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return json_decode(self::raw(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** `{ $repeat: s, count: n }` anywhere in a value is s repeated n times. */
    public static function expand(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (isset($value['$repeat'], $value['count']) && is_string($value['$repeat']) && is_int($value['count'])) {
            return str_repeat($value['$repeat'], $value['count']);
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::expand($item);
        }

        return $out;
    }

    /**
     * Steps with `repeat` become that many steps, `every` ms apart.
     *
     * @param  array<string, mixed>  $case
     * @return list<array<string, mixed>>
     */
    public static function steps(array $case): array
    {
        $out = [];
        foreach ($case['steps'] as $step) {
            if (empty($step['repeat'])) {
                $out[] = $step;

                continue;
            }
            for ($i = 0; $i < $step['repeat']; $i++) {
                $copy = $step;
                unset($copy['repeat']);
                $copy['at'] = ($step['at'] ?? 0) + $i * ($step['every'] ?? 0);
                $out[] = $copy;
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $case */
    public static function host(array $case): EngineHost
    {
        $h = $case['host'];
        $middleware = null;
        if (isset($case['middleware']) && is_array($case['middleware'])) {
            $middleware = array_map(static function (mixed $entry): mixed {
                if (is_string($entry)) {
                    return $entry;
                }
                if ($entry['host'] === 'refuse') {
                    return static fn (): array => ['allow' => false, 'reason' => $entry['reason'], 'status' => $entry['status']];
                }

                return static fn (PipelineContext $ctx, array $intent, \Closure $next): array => $intent['kind'] === $entry['kind']
                    ? ['allow' => false, 'reason' => $entry['reason'], 'status' => $entry['status']]
                    : $next();
            }, $case['middleware']);
        }
        $members = $h['members'];
        $authorize = $h['authorize'];
        $predicates = $h['predicates'] ?? [];

        return new EngineHost(
            identity: $h['identify'],
            config: $h['config'] ? Config::resolve($case['config'] ?? []) : null,
            members: $members === null ? null : static fn (string $ref): bool => in_array($ref, $members, true),
            authorize: $authorize === null ? null : static function (string $ref, string $ability) use ($authorize): bool {
                foreach ($authorize[$ref] ?? [] as $glob) {
                    if (Glob::matches($glob, $ability)) {
                        return true;
                    }
                }

                return false;
            },
            predicate: static fn (string $name): ?bool => array_key_exists($name, $predicates) ? $predicates[$name] : null,
            middleware: $middleware,
        );
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    public static function state(array $case): array
    {
        return SessionState::create(
            id: 's1',
            state: $case['session']['state'],
            starter: $case['session']['starter'],
            participants: array_map(static fn (array $p): array => ['ref' => $p['ref'], 'route' => $p['route']], $case['session']['participants']),
            now: 0,
        );
    }
}
