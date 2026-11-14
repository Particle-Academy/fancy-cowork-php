<?php

declare(strict_types=1);

namespace FancyCowork\Engine;

/**
 * A session's configuration: consent, control, routing, limits. Plain arrays,
 * the same shape as fancy-cowork-js's `SessionConfig`. Whatever a host's config
 * resolver omits takes the secure default; every limit stays configurable.
 */
final class Config
{
    public const array DEFAULT = [
        'consent' => 'notify',
        'control' => ['mode' => 'baton'],
        'routing' => 'server',
        'limits' => ['allow' => [], 'deny' => [], 'mask' => [], 'viewOnly' => []],
        'rate' => ['intentsPerSecond' => 10, 'visitsPerSecond' => 1, 'appPerMinute' => 30, 'startsPerMinute' => 5],
        'size' => ['intentBytes' => 8192, 'appBytes' => 16384],
        'maxParticipants' => 16,
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function resolve(array $input = []): array
    {
        $config = [...self::DEFAULT, ...$input];
        $config['control'] = isset($input['control']) && is_array($input['control']) ? $input['control'] : self::DEFAULT['control'];
        foreach (['limits', 'rate', 'size'] as $group) {
            $config[$group] = [...self::DEFAULT[$group], ...(isset($input[$group]) && is_array($input[$group]) ? $input[$group] : [])];
        }

        return $config;
    }
}
