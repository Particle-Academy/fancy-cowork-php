<?php

declare(strict_types=1);

namespace FancyCowork\Support;

/**
 * A ULID (48-bit millisecond time + 80 random bits, Crockford base32, 26
 * characters), written here so the package needs no uid library at runtime.
 */
final class Ulid
{
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $ms = null): string
    {
        $ms ??= (int) floor(microtime(true) * 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$ms % 32].$time;
            $ms = intdiv($ms, 32);
        }
        $random = '';
        $bytes = random_bytes(16);
        for ($i = 0; $i < 16; $i++) {
            $random .= self::ALPHABET[ord($bytes[$i]) % 32];
        }

        return $time.$random;
    }
}
