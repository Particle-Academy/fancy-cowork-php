<?php

declare(strict_types=1);

namespace FancyCowork;

/**
 * The Pusher-protocol channel signature Reverb and Pusher both check:
 * `key:HMAC-SHA256(secret, "socket_id:channel[:channel_data]")`. Used by
 * `POST {prefix}/auth`, which works for signed-in and anonymous participants
 * alike because it asks the host's `identify`, never a Laravel guard.
 */
final class PusherSignature
{
    /** @return array{auth: string, channel_data?: string} */
    public static function sign(string $key, string $secret, string $socketId, string $channel, ?string $channelData = null): array
    {
        $subject = $channelData === null ? "{$socketId}:{$channel}" : "{$socketId}:{$channel}:{$channelData}";
        $signed = ['auth' => $key.':'.hash_hmac('sha256', $subject, $secret)];
        if ($channelData !== null) {
            $signed['channel_data'] = $channelData;
        }

        return $signed;
    }
}
