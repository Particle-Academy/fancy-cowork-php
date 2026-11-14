<?php

declare(strict_types=1);

namespace FancyCowork\Engine;

/** `*` is any run of characters; everything else is literal. Anchored. */
final class Glob
{
    public static function matches(string $pattern, string $value): bool
    {
        $parts = array_map(static fn (string $part): string => preg_quote($part, '#'), explode('*', $pattern));

        return preg_match('#^'.implode('.*', $parts).'$#sD', $value) === 1;
    }

    /** @param  list<string>  $patterns */
    public static function any(array $patterns, string $value): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && self::matches($pattern, $value)) {
                return true;
            }
        }

        return false;
    }
}
