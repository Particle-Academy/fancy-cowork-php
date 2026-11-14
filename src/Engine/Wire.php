<?php

declare(strict_types=1);

namespace FancyCowork\Engine;

/**
 * Small pure helpers for contract v1: what a valid intent looks like, how its
 * size is measured, and what a same-origin path is.
 */
final class Wire
{
    public const array INTENT_KINDS = ['visit', 'scroll', 'field', 'focus', 'click', 'submit', 'control', 'app'];

    /** Kinds that act on a view: they need an envelope `view` and `revision`. */
    public const array VIEW_KINDS = ['visit', 'scroll', 'field', 'focus', 'click', 'submit'];

    public const array CONTROL_OPS = ['request', 'grant', 'release', 'revoke'];

    public static function isViewKind(string $kind): bool
    {
        return in_array($kind, self::VIEW_KINDS, true);
    }

    /** A JSON object: an array that is empty or not a list. */
    public static function isRecord(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /** A string of 1..256 characters with no control characters. Handles and refs share the rule. */
    public static function isHandle(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }
        $length = mb_strlen($value, 'UTF-8');

        return $length <= 256 && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }

    /**
     * Names that are never a ref. PHP arrays have no prototype, so nothing here can be
     * polluted; they are refused anyway so the PHP, Node and browser legs agree (the JS
     * legs key ordinary objects by ref, where these three reach Object.prototype).
     */
    public const array RESERVED_REFS = ['__proto__', 'constructor', 'prototype'];

    public static function isRef(mixed $value): bool
    {
        return self::isHandle($value) && ! in_array($value, self::RESERVED_REFS, true);
    }

    public static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    /** The path of a relative url: everything before `?` or `#`. */
    public static function routeOf(string $url): string
    {
        return substr($url, 0, strcspn($url, '?#'));
    }

    /** A relative same-origin path: `/`, not `//` or `/\`, no backslash, whitespace or control character. */
    public static function isSameOriginPath(string $url): bool
    {
        if (! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            return false;
        }

        // JavaScript's \s, spelled out: PCRE's \s does not cover the Unicode spaces.
        $unsafe = '/[\s\x{00}-\x{1f}\x{7f}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]/u';

        return preg_match($unsafe, $url) === 0;
    }

    /** UTF-8 bytes of the JSON encoding, keys in received order, no whitespace, slashes and non-ASCII unescaped. */
    public static function jsonBytes(mixed $value): int
    {
        // No JSON_PRESERVE_ZERO_FRACTION: 1.0 encodes as `1`, as JSON.stringify does.
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? PHP_INT_MAX : strlen($encoded);
    }

    /** @param  array<string, mixed>  $intent */
    public static function handleOf(array $intent): ?string
    {
        return match ($intent['kind']) {
            'field', 'focus', 'click', 'submit' => $intent['handle'],
            'scroll' => $intent['anchor'] ?? null,
            default => null,
        };
    }

    /**
     * Structural validation of one intent and its envelope. Runs before anything else is asked.
     *
     * @param  array<string, mixed>  $envelope
     */
    public static function validIntent(mixed $intent, array $envelope): bool
    {
        if (! self::isRecord($intent) || ! isset($intent['kind']) || ! in_array($intent['kind'], self::INTENT_KINDS, true)) {
            return false;
        }
        if (! self::isRecord($envelope)) {
            return false;
        }
        if (array_key_exists('to', $envelope)) {
            $to = $envelope['to'];
            if (! is_array($to) || ! array_is_list($to) || count($to) > 64) {
                return false;
            }
            foreach ($to as $ref) {
                if (! self::isRef($ref)) {
                    return false;
                }
            }
        }
        if (array_key_exists('view', $envelope) && ! self::isRef($envelope['view'])) {
            return false;
        }
        $kind = $intent['kind'];
        if ((self::isViewKind($kind) || $kind === 'control') && ! self::isRef($envelope['view'] ?? null)) {
            return false;
        }
        if (self::isViewKind($kind)) {
            $revision = $envelope['revision'] ?? null;
            if (! self::isInteger($revision) || $revision < 0) {
                return false;
            }
        }

        return match ($kind) {
            'visit' => is_string($intent['url'] ?? null) && mb_check_encoding($intent['url'], 'UTF-8')
                && mb_strlen($intent['url'], 'UTF-8') >= 1 && mb_strlen($intent['url'], 'UTF-8') <= 2048,
            'scroll' => (is_int($intent['y'] ?? null) || is_float($intent['y'] ?? null)) && is_finite((float) $intent['y']) && $intent['y'] >= 0
                && (! array_key_exists('anchor', $intent) || self::isHandle($intent['anchor'])),
            'field' => self::isHandle($intent['handle'] ?? null) && array_key_exists('value', $intent),
            'focus', 'click', 'submit' => self::isHandle($intent['handle'] ?? null),
            'control' => in_array($intent['op'] ?? null, self::CONTROL_OPS, true)
                && (! array_key_exists('target', $intent) || self::isRef($intent['target']))
                && ($intent['op'] !== 'grant' || self::isRef($intent['target'] ?? null)),
            'app' => is_string($intent['topic'] ?? null) && preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/D', $intent['topic']) === 1
                && array_key_exists('payload', $intent),
        };
    }
}
