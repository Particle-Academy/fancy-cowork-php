<?php

declare(strict_types=1);

namespace FancyCowork\Broadcasting;

use FancyCowork\PusherSignature;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Laravel broadcaster driver `fancy-pusher`: Laravel's own Pusher conventions
 * (channel names, `/broadcasting/auth`), publishing through
 * {@see PusherHttpClient}. Point an existing Pusher- or Reverb-shaped connection
 * at it, e.g. Genie's WebSockets service through its REVERB_* variables:
 *
 *     'reverb' => ['driver' => 'fancy-pusher', 'key' => env('REVERB_APP_KEY'),
 *                  'secret' => env('REVERB_APP_SECRET'), 'app_id' => env('REVERB_APP_ID'),
 *                  'options' => ['host' => env('REVERB_HOST'), 'port' => env('REVERB_PORT'),
 *                                'scheme' => env('REVERB_SCHEME', 'https')]],
 */
final class PusherBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function __construct(private readonly PusherHttpClient $client) {}

    /**
     * Laravel's own rule, unchanged: a private or presence channel needs a signed-in
     * user here. Anonymous co-work participants use the package's `POST
     * {prefix}/auth`, which asks the host's `identify` instead of a guard.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function auth($request)
    {
        $channelName = $this->normalizeChannelName((string) $request->channel_name);
        if ($channelName === '' || ($this->isGuardedChannel((string) $request->channel_name) && ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $channelName);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     * @return array{auth: string, channel_data?: string}
     */
    public function validAuthenticationResponse($request, $result)
    {
        $channel = (string) $request->channel_name;
        $socket = (string) $request->socket_id;
        if (str_starts_with($channel, 'private')) {
            return PusherSignature::sign($this->client->key(), $this->client->secret(), $socket, $channel);
        }

        // Presence: the member id comes from the channel callback's answer (the
        // server's own identity for the participant), else the signed-in user.
        $userId = is_array($result) ? ($result['id'] ?? $result['ref'] ?? null) : null;
        if ($userId === null) {
            $user = $this->retrieveUser($request, $this->normalizeChannelName($channel));
            $userId = $user === null ? null : (method_exists($user, 'getAuthIdentifierForBroadcasting') ? $user->getAuthIdentifierForBroadcasting() : $user->getAuthIdentifier());
        }
        if ($userId === null) {
            throw new AccessDeniedHttpException;
        }
        $channelData = json_encode(['user_id' => $userId, 'user_info' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return PusherSignature::sign($this->client->key(), $this->client->secret(), $socket, $channel, (string) $channelData);
    }

    /** @param  array<string, mixed>  $payload */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        $socket = Arr::pull($payload, 'socket');
        $this->client->trigger($this->formatChannels($channels), (string) $event, $payload, is_string($socket) ? $socket : null);
    }
}
