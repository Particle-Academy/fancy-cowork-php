<?php

declare(strict_types=1);

namespace FancyCowork;

use Closure;
use Illuminate\Http\Request;

/**
 * OPTIONAL signed guest tokens, for the anonymous support case. The package has
 * no guest concept: this is one `identify` resolver a host may bind, or ignore.
 *
 *     $token = app(GuestTokens::class)->issue();          // hand it to the visitor
 *     Cowork::identify(app(GuestTokens::class)->resolver(
 *         fallback: fn (Request $r) => ...your signed-in participants...,
 *     ));
 *
 * A token is `v1.<base64url {sub, exp}>.<base64url HMAC-SHA256>`, keyed by a
 * key derived from the app key, checked in constant time, and expiring after
 * `fancy-cowork.guest.ttl` seconds. It resolves to the ref `guest:<sub>`. It
 * proves the token came from this app; it says nothing else about the visitor.
 */
final class GuestTokens
{
    private readonly string $key;

    public function __construct(
        string $secret,
        private readonly int $ttl = 7200,
        private readonly string $header = 'X-Cowork-Guest',
    ) {
        $this->key = hash_hmac('sha256', 'fancy-cowork/guest-token/v1', $secret, true);
    }

    /** Derive from Laravel's `app.key` (with or without its `base64:` prefix). */
    public static function fromAppKey(string $appKey, int $ttl = 7200, string $header = 'X-Cowork-Guest'): self
    {
        $secret = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

        return new self($secret, $ttl, $header);
    }

    public function issue(?string $id = null, ?int $now = null): string
    {
        $id ??= self::b64(random_bytes(16));
        $payload = self::b64((string) json_encode(['sub' => $id, 'exp' => ($now ?? time()) + $this->ttl], JSON_THROW_ON_ERROR));

        return "v1.{$payload}.".self::b64(hash_hmac('sha256', "v1.{$payload}", $this->key, true));
    }

    /** The guest id, or null for anything tampered, malformed, foreign or expired. */
    public function verify(string $token, ?int $now = null): ?string
    {
        if (strlen($token) > 1024) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            return null;
        }
        [, $payload, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', "v1.{$payload}", $this->key, true));
        if (! hash_equals($expected, $signature)) {
            return null;
        }
        $claims = json_decode((string) self::unb64($payload), true);
        if (! is_array($claims) || ! is_string($claims['sub'] ?? null) || ! is_int($claims['exp'] ?? null)) {
            return null;
        }
        if (($now ?? time()) >= $claims['exp'] || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $claims['sub']) !== 1) {
            return null;
        }

        return $claims['sub'];
    }

    /**
     * An `identify` resolver: the guest named by a valid token in the header,
     * else whatever `$fallback` says (else nobody).
     *
     * @param  (Closure(Request): ?Participant)|null  $fallback
     * @return Closure(Request): ?Participant
     */
    public function resolver(?Closure $fallback = null): Closure
    {
        return function (Request $request) use ($fallback): ?Participant {
            $token = $request->header($this->header);
            $id = is_string($token) && $token !== '' ? $this->verify($token) : null;
            if ($id !== null) {
                return new Participant('guest:'.$id);
            }

            return $fallback !== null ? $fallback($request) : null;
        };
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string|false
    {
        return base64_decode(strtr($text, '-_', '+/'), true);
    }
}
