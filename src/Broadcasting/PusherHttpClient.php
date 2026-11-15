<?php

declare(strict_types=1);

namespace FancyCowork\Broadcasting;

use Closure;
use GuzzleHttp\Utils;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;
use Throwable;

/**
 * Publishes events to a Pusher-protocol server over its HTTP API
 * (`POST /apps/{app_id}/events`), with Laravel's HTTP client. Our own code, so a
 * host needs neither pusher/pusher-php-server nor laravel/reverb: Genie's
 * WebSockets service, Sockudo, Reverb and Pusher all accept it.
 */
final class PusherHttpClient
{
    /** The protocol's per-event payload limit (Pusher and Reverb defaults). */
    public const int MAX_DATA_BYTES = 10240;

    /** The protocol's per-request channel limit. */
    public const int MAX_CHANNELS = 100;

    /** A 429 is retried at most this many times per request... */
    public const int MAX_RETRIES = 2;

    /** ...and never waits more than this in total (ms); a longer Retry-After is not waited out. */
    public const int MAX_RETRY_WAIT_MS = 3000;

    /**
     * The ONE Guzzle handler every publish goes through, built on the first. A
     * handler keeps cURL's easy handles, and with them the open connection and the
     * TLS session, so a publish after the first in this process skips the handshake.
     * (Laravel builds a new handler per request unless one is set.) Set through
     * PendingRequest::setHandler, below Laravel's own middleware, so Http::fake and
     * the request recorder still see every publish.
     *
     * @var callable|null
     */
    private $handler = null;

    /**
     * @param  Closure(): HttpFactory  $http  resolved per send, so a host (or a test) can swap it
     * @param  Closure(): int|null  $clock  seconds
     * @param  Closure(): callable|null  $handlerFactory  builds the persistent handler; default Guzzle's best for this PHP
     */
    public function __construct(
        private readonly Closure $http,
        private readonly string $appId,
        private readonly string $key,
        private readonly string $secret,
        private readonly string $baseUrl,
        private readonly int $timeout = 5,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $handlerFactory = null,
        /** Sleeps for the given milliseconds before a retry; default usleep. Tests record instead. */
        private readonly ?Closure $sleep = null,
    ) {}

    /**
     * From a Laravel broadcasting connection shaped like Pusher's or Reverb's:
     * `key`, `secret`, `app_id`, and `options.host` / `port` / `scheme` / `useTLS`.
     *
     * @param  Closure(): HttpFactory  $http
     * @param  array<string, mixed>  $config
     * @param  Closure(): callable|null  $handlerFactory
     */
    public static function fromConfig(Closure $http, array $config, ?Closure $handlerFactory = null): self
    {
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        foreach (['key' => $config['key'] ?? null, 'secret' => $config['secret'] ?? null, 'app_id' => $config['app_id'] ?? null, 'options.host' => $options['host'] ?? null] as $name => $value) {
            if (! is_scalar($value) || (string) $value === '') {
                // Name the setting, never its value: secrets end up in logs.
                throw new InvalidArgumentException("fancy-pusher broadcasting connection: `{$name}` is missing.");
            }
        }
        $tls = ($options['useTLS'] ?? null) === true || ($options['scheme'] ?? null) === 'https';
        $scheme = $tls ? 'https' : 'http';
        $port = isset($options['port']) && is_numeric($options['port']) ? (int) $options['port'] : ($tls ? 443 : 80);
        $host = (string) $options['host'];

        return new self(
            $http,
            (string) $config['app_id'],
            (string) $config['key'],
            (string) $config['secret'],
            "{$scheme}://{$host}:{$port}",
            isset($options['timeout']) && is_numeric($options['timeout']) ? (int) $options['timeout'] : 5,
            null,
            $handlerFactory,
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * Publish one event to the channels, excluding `$socketId` when it is a valid
     * socket id (the sender's own connection). Throws {@see BroadcastException}.
     *
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $payload
     */
    public function trigger(array $channels, string $event, array $payload, ?string $socketId = null): void
    {
        $data = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($data === false) {
            throw new BroadcastException("fancy-pusher: the payload for `{$event}` cannot be encoded as JSON.");
        }
        if (strlen($data) > self::MAX_DATA_BYTES) {
            throw new BroadcastException(sprintf('fancy-pusher: the payload for `%s` is %d bytes; the protocol limit is %d.', $event, strlen($data), self::MAX_DATA_BYTES));
        }
        $socket = $socketId !== null && preg_match('/^\d+\.\d+$/', $socketId) === 1 ? $socketId : null;
        $path = '/apps/'.rawurlencode($this->appId).'/events';

        foreach (array_chunk(array_values($channels), self::MAX_CHANNELS) as $chunk) {
            $body = json_encode(
                ['name' => $event, 'channels' => $chunk, 'data' => $data, ...($socket !== null ? ['socket_id' => $socket] : [])],
                // ASCII-only (non-ASCII escaped as \uXXXX): some Pusher-protocol servers,
                // Genie's WebSockets service among them, read the body as Latin-1, so raw
                // UTF-8 arrives garbled. body_md5 is signed over exactly these bytes.
                JSON_UNESCAPED_SLASHES,
            );
            if ($body === false) {
                throw new BroadcastException("fancy-pusher: the request for `{$event}` cannot be encoded.");
            }
            $this->publish($event, $path, $body);
        }
    }

    /**
     * One signed POST, retrying a 429 after its Retry-After (seconds or an HTTP
     * date), at most MAX_RETRIES times and never waiting more than
     * MAX_RETRY_WAIT_MS in total. Each attempt is signed afresh, so its
     * auth_timestamp is current. Any other refusal is not retried.
     */
    private function publish(string $event, string $path, string $body): void
    {
        $waited = 0;
        for ($attempt = 0; ; $attempt++) {
            $now = $this->clock !== null ? ($this->clock)() : time();
            $query = PusherProtocol::eventQuery($this->key, $this->secret, $path, $body, $now);

            try {
                $this->handler ??= $this->handlerFactory !== null ? ($this->handlerFactory)() : Utils::chooseHandler();
                $response = ($this->http)()
                    ->setHandler($this->handler)
                    ->timeout($this->timeout)
                    ->acceptJson()
                    ->withBody($body, 'application/json')
                    ->post($this->baseUrl.$path.'?'.http_build_query($query));
            } catch (Throwable $e) {
                throw new BroadcastException("fancy-pusher: publishing `{$event}` failed: ".$e::class.'.');
            }
            if ($response->successful()) {
                return;
            }
            if ($response->status() === 429 && $attempt < self::MAX_RETRIES) {
                $wait = $this->retryAfterMs($response->header('Retry-After'), $now);
                if ($waited + $wait <= self::MAX_RETRY_WAIT_MS) {
                    $this->sleep !== null ? ($this->sleep)($wait) : usleep($wait * 1000);
                    $waited += $wait;

                    continue;
                }
            }

            throw new BroadcastException("fancy-pusher: publishing `{$event}` was refused with HTTP {$response->status()}.");
        }
    }

    /** Retry-After as milliseconds: delta-seconds or an HTTP date; 1 s when absent or unreadable. */
    private function retryAfterMs(string $header, int $now): int
    {
        $header = trim($header);
        if ($header !== '' && ctype_digit($header)) {
            return (int) $header * 1000;
        }
        $at = $header === '' ? false : strtotime($header);

        return $at === false ? 1000 : max(0, $at - $now) * 1000;
    }
}
