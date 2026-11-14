<?php

declare(strict_types=1);

namespace FancyCowork\Broadcasting;

/**
 * The Pusher protocol's HTTP API request signature, which every Pusher-protocol
 * server checks (Pusher, Reverb, Sockudo, Genie's WebSockets service):
 *
 *     auth_signature = HMAC-SHA256(secret, "METHOD\nPATH\nsorted query")
 *
 * where the query is every parameter except `auth_signature`, names lowercased
 * and sorted, joined unencoded as `name=value&...`. Ours, not a dependency;
 * pinned to the protocol's documented example in tests/Unit/PusherProtocolTest.
 */
final class PusherProtocol
{
    public const string AUTH_VERSION = '1.0';

    /** @param  array<string, string>  $params */
    public static function signRequest(string $method, string $path, array $params, string $secret): string
    {
        $pairs = [];
        foreach ($params as $name => $value) {
            $name = strtolower((string) $name);
            if ($name === 'auth_signature') {
                continue;
            }
            $pairs[$name] = (string) $value;
        }
        ksort($pairs, SORT_STRING);
        $query = implode('&', array_map(fn (string $name, string $value): string => "{$name}={$value}", array_keys($pairs), $pairs));

        return hash_hmac('sha256', strtoupper($method)."\n{$path}\n{$query}", $secret);
    }

    /**
     * The full query string for `POST {path}` carrying `$body`, signature included.
     *
     * @return array{auth_key: string, auth_timestamp: string, auth_version: string, body_md5: string, auth_signature: string}
     */
    public static function eventQuery(string $key, string $secret, string $path, string $body, int $timestamp): array
    {
        $params = [
            'auth_key' => $key,
            'auth_timestamp' => (string) $timestamp,
            'auth_version' => self::AUTH_VERSION,
            'body_md5' => md5($body),
        ];
        $params['auth_signature'] = self::signRequest('POST', $path, $params, $secret);

        return $params;
    }
}
