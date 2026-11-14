<?php

declare(strict_types=1);

namespace FancyCowork\Tests\Support;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;

/** Records every broadcast, in order, instead of sending it. */
final class CaptureBroadcaster extends Broadcaster
{
    /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
    public static array $sent = [];

    public function auth($request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return $result;
    }

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        self::$sent[] = ['channels' => array_map('strval', $channels), 'event' => $event, 'payload' => $payload];
    }
}
